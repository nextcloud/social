<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\TranslationUnavailableException;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Translation;
use OCP\TaskProcessing\Exception\Exception as TaskProcessingException;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Translating a status, through whatever translation provider this Nextcloud
 * has.
 *
 * There is no translation engine in this app and there should not be one: a
 * Nextcloud that can translate already says so — a provider is installed and
 * configured server-wide, and the same one answers Talk, Mail and the
 * assistant. This asks that provider, which is why an instance with none
 * announces `translation.enabled: false` and answers the route with a 503
 * rather than handing back the original text.
 *
 * That is the bug this replaces: `translate` used to return the post
 * unchanged. A client cannot tell that from a translation into a language the
 * reader happens to write in, so the button appeared to work and quietly did
 * nothing. An absent capability a client is told about is a button that is
 * never drawn; a capability that lies is one that is drawn and is wrong.
 *
 * What gets translated: the body and the content warning always, then the poll
 * options and the pictures' descriptions while the budget lasts. Each text is
 * a task of its own — that is the shape of the provider API — so a post with
 * ten pictures and a poll would be twenty provider round-trips inside one HTTP
 * request. `MAX_TEXTS` bounds it, and anything past the bound is *left out of
 * the entity* rather than returned untranslated: Mastodon's Translation
 * entity lists only what was translated, and a client shows the original for
 * whatever is not in the list.
 */
class TranslationService {
	/**
	 * How many texts one translation request may send to the provider.
	 *
	 * Two are always spent on the body and the warning. The rest is what the
	 * poll options and the alt texts share.
	 */
	public const MAX_TEXTS = 12;

	/** What a source language of "work it out yourself" is called, by provider. */
	private const DETECT_VALUES = ['detect_language', 'auto', 'auto_detect', 'autodetect'];

	/** @var array<string, list<string>>|null the enum values, read once per request */
	private ?array $enumValues = null;

	public function __construct(
		private IManager $taskManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether anything on this server can translate.
	 *
	 * Asked of the task type rather than of `hasProviders()`: a Nextcloud with
	 * a speech-to-text provider and nothing else has providers and still
	 * cannot translate a word.
	 */
	public function isAvailable(): bool {
		try {
			return in_array(TextToTextTranslate::ID, $this->taskManager->getAvailableTaskTypeIds(), true);
		} catch (Throwable $e) {
			// a provider that throws while listing itself is not one to offer
			$this->logger->debug('[TranslationService] could not list task types', ['exception' => $e]);

			return false;
		}
	}

	/**
	 * Mastodon's `/api/v1/instance/translation_languages`: for each language
	 * that can be translated *from*, the languages it can be translated *to*.
	 *
	 * Built from the provider's own enum values, so it is the truth about this
	 * server rather than a list copied from somebody's documentation. A
	 * provider that only auto-detects offers no source languages, and the
	 * answer is then an empty object — which is what a client needs to know.
	 *
	 * @return array<string, list<string>>
	 */
	public function languages(): array {
		$sources = $this->concrete($this->values('origin_language'));
		$targets = $this->concrete($this->values('target_language'));

		$languages = [];
		foreach ($sources as $source) {
			$to = array_values(array_filter($targets, static fn (string $t): bool => $t !== $source));
			if ($to !== []) {
				$languages[$source] = $to;
			}
		}

		return $languages;
	}

	/**
	 * One status, in the reader's language.
	 *
	 * @throws TranslationUnavailableException when no provider answered
	 */
	public function translateStatus(Stream $post, string $target, ?string $userId): Translation {
		if (!$this->isAvailable()) {
			throw new TranslationUnavailableException('no translation provider is configured');
		}

		$source = $this->sourceFor($post->getLanguage());
		$budget = self::MAX_TEXTS;

		$translation = new Translation();
		$translation->setDetectedSourceLanguage(
			($post->getLanguage() !== '') ? $post->getLanguage() : 'und'
		);
		$translation->setProvider($this->providerName());

		$translation->setContent($this->run($post->getContent(), $source, $target, $userId));
		$budget--;

		if ($post->getSpoilerText() !== '') {
			$translation->setSpoilerText($this->run($post->getSpoilerText(), $source, $target, $userId));
			$budget--;
		}

		if ($post instanceof Question) {
			$options = [];
			foreach ($post->getOptions() as $option) {
				$title = (string)($option['title'] ?? '');
				if ($budget < 1 || $title === '') {
					break;
				}

				$options[] = ['title' => $this->run($title, $source, $target, $userId)];
				$budget--;
			}

			if ($options !== []) {
				// the poll's id is the status's, as `Question::jsonSerialize()`
				// serves it: a client matches the translated options onto the
				// poll it already holds by that id
				$translation->setPoll((string)$post->getNid(), $options);
			}
		}

		$attachments = [];
		foreach ($post->getAttachments() as $attachment) {
			$description = $attachment->getDescription();
			if ($budget < 1 || $description === '') {
				break;
			}

			$attachments[] = [
				'id' => $attachment->getId(),
				'description' => $this->run($description, $source, $target, $userId),
			];
			$budget--;
		}
		$translation->setMediaAttachments($attachments);

		return $translation;
	}

	/**
	 * One text through the provider.
	 *
	 * A provider that fails on one part of a post fails the whole request: a
	 * Translation entity with a translated body and an untranslated warning
	 * reads as a post whose warning was written in the reader's language, and
	 * nobody could tell.
	 *
	 * @throws TranslationUnavailableException
	 */
	private function run(string $text, string $source, string $target, ?string $userId): string {
		if (trim($text) === '') {
			return $text;
		}

		$task = new Task(
			TextToTextTranslate::ID,
			[
				'input' => $text,
				'origin_language' => $source,
				'target_language' => $target,
			],
			'social',
			$userId
		);

		try {
			$finished = $this->taskManager->runTask($task);
		} catch (TaskProcessingException|Throwable $e) {
			$this->logger->warning('[TranslationService] the translation provider failed', [
				'exception' => $e,
			]);

			throw new TranslationUnavailableException('the translation provider failed');
		}

		if ($finished->getStatus() !== Task::STATUS_SUCCESSFUL) {
			throw new TranslationUnavailableException(
				$finished->getErrorMessage() ?? 'the translation provider failed'
			);
		}

		$output = $finished->getOutput()['output'] ?? '';
		if (!is_string($output) || $output === '') {
			throw new TranslationUnavailableException('the translation provider returned nothing');
		}

		return $output;
	}

	/**
	 * What to tell the provider the post is written in.
	 *
	 * The post's own declaration first — this app stores it from `contentMap`,
	 * so it is what the author said rather than a guess — but only when the
	 * provider offers it; otherwise auto-detection, and failing that whatever
	 * the post claims, so that a provider with an open enum still gets a
	 * language rather than an empty string.
	 */
	private function sourceFor(string $language): string {
		$offered = $this->values('origin_language');

		if ($language !== '' && in_array($language, $offered, true)) {
			return $language;
		}

		foreach ($offered as $value) {
			if (in_array(strtolower($value), self::DETECT_VALUES, true)) {
				return $value;
			}
		}

		return $language;
	}

	/** The provider's name, for the `provider` field, or its id, or nothing. */
	private function providerName(): string {
		try {
			return $this->taskManager->getPreferredProvider(TextToTextTranslate::ID)->getName();
		} catch (Throwable $e) {
			return '';
		}
	}

	/**
	 * The values one enum input of the translate task accepts.
	 *
	 * @return list<string>
	 */
	private function values(string $key): array {
		if ($this->enumValues === null) {
			$this->enumValues = $this->readEnumValues();
		}

		return $this->enumValues[$key] ?? [];
	}

	/**
	 * @return array<string, list<string>>
	 */
	private function readEnumValues(): array {
		try {
			$types = $this->taskManager->getAvailableTaskTypes();
		} catch (Throwable $e) {
			$this->logger->debug('[TranslationService] could not read task types', ['exception' => $e]);

			return [];
		}

		$enums = $types[TextToTextTranslate::ID]['inputShapeEnumValues'] ?? [];

		$values = [];
		foreach ($enums as $key => $entries) {
			$values[(string)$key] = array_values(
				array_filter(
					array_map(
						static fn (mixed $entry): string
							=> ($entry instanceof ShapeEnumValue) ? $entry->getValue() : '',
						is_array($entries) ? $entries : []
					),
					static fn (string $value): bool => $value !== ''
				)
			);
		}

		return $values;
	}

	/**
	 * The language codes among a list of enum values: the ones that name a
	 * language, rather than the pseudo-value that means "detect it".
	 *
	 * @param list<string> $values
	 * @return list<string>
	 */
	private function concrete(array $values): array {
		return array_values(
			array_filter(
				$values,
				static fn (string $value): bool
					=> !in_array(strtolower($value), self::DETECT_VALUES, true)
			)
		);
	}
}
