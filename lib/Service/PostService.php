<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\Post;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

class PostService {
	public const POLL_MAX_OPTIONS = 4;
	private StreamService $streamService;
	private AccountService $accountService;
	private ActivityService $activityService;
	private IFactory $l10nFactory;
	private IUserManager $userManager;
	private LoggerInterface $logger;

	/**
	 * The languages whose region subtag names a different written language,
	 * and which Mastodon therefore offers as post languages of their own.
	 * For every other language a Nextcloud locale's region describes the UI,
	 * not the text: `en_GB` is English.
	 */
	private const REGIONAL_LANGUAGES = ['pt', 'zh'];

	public function __construct(
		StreamService $streamService, AccountService $accountService, ActivityService $activityService,
		IFactory $l10nFactory, IUserManager $userManager, LoggerInterface $logger,
	) {
		$this->streamService = $streamService;
		$this->accountService = $accountService;
		$this->activityService = $activityService;
		$this->l10nFactory = $l10nFactory;
		$this->userManager = $userManager;
		$this->logger = $logger;
	}

	/**
	 * @param Post $post
	 * @param string $token
	 *
	 * @return ACore
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws StreamNotFoundException
	 * @throws UnauthorizedFediverseException
	 */
	public function createPost(Post $post, string &$token = ''): ?ACore {
		$this->fixRecipientAndHashtags($post);

		$note = new Note();
		if ($post->hasPoll()) {
			$poll = $post->getPoll();
			$options = array_slice(array_values(array_filter(
				array_map('strval', $poll['options'] ?? []),
				static fn (string $option): bool => trim($option) !== ''
			)), 0, self::POLL_MAX_OPTIONS);
			if (count($options) < 2) {
				throw new \InvalidArgumentException('a poll needs at least two options');
			}

			$note = new Question();
			$note->setPollData(
				$options,
				(bool)($poll['multiple'] ?? false),
				min(max((int)($poll['expires_in'] ?? 86400), 300), 7 * 86400)
			);
		}
		$actor = $post->getActor();
		$this->streamService->assignItem($note, $actor, $post->getType());

		$note->setAttributedTo($actor->getId());
		$note->setContent(nl2br(htmlentities($post->getContent(), ENT_QUOTES)));
		// The warning rides as the object's `summary`, which is what every other
		// server reads it from — and unlike the content it is plain text
		// wherever it is read: `spoiler_text` to a client, interpolated rather
		// than rendered by this app's own frontend. Encoding it entity by entity
		// federated `Bob&#039;s finale` to every other instance and baked those
		// entities into the next edit, so markup is dropped instead of encoded:
		// nothing downstream has to undo it, and editPost() agrees.
		$note->setSpoilerText(strip_tags($post->getSpoilerText()));
		$note->setSensitive($post->isSensitive());
		$note->setAttachments($post->getMedias());
		$note->setVisibility($post->getType());
		$note->setLanguage($this->languageFor($post->getLanguage(), $actor));

		$this->streamService->replyTo($note, $post->getReplyTo());
		$this->streamService->addRecipients($note, $post->getType(), $post->getTo());
		$this->streamService->addHashtags($note, $post->getHashtags());
		//		$this->streamService->addAttachments($note, $post->getDocuments());

		// the stored source is what survives the database and federates on
		// Update — a poll's options and counts, and for every post the
		// language, which has no column of its own: snapshot the assembled
		// object
		$this->snapshotSource($note);

		$token = $this->activityService->createActivity($actor, $note, $activity);
		$this->accountService->cacheLocalActorDetailCount($actor);

		$this->logger->debug('Activity: ' . json_encode($activity));

		return $activity;
	}

	/**
	 * @param ?string $language the language the client sent, null or empty to
	 *                          keep the post's; a post that never had one gets
	 *                          the poster's default
	 *
	 * @throws \Exception
	 */
	public function editPost(
		int $nid, Person $actor, string $content, ?string $spoilerText = null, ?bool $sensitive = null,
		?string $language = null,
	): Stream {
		$stream = $this->streamService->getStreamByNid($nid);

		if ($stream->getAttributedTo() !== $actor->getId()) {
			throw new \Exception('Not authorized to edit this post');
		}

		$stream->setContent(nl2br(htmlentities($content, ENT_QUOTES)));
		if ($spoilerText !== null) {
			$stream->setSpoilerText(strip_tags($spoilerText));
		}
		if ($sensitive !== null) {
			$stream->setSensitive($sensitive);
		}
		$stream->setLanguage($this->languageFor((string)$language, $actor, $stream->getLanguage()));

		// `published` stays the creation time. The edit used to be stamped
		// there instead, and Mastodon — which reads an Update without
		// `updated` as an implicit one — refreshed the poll counters and threw
		// the new content away. UTC and second-resolution like `published`.
		$stream->setUpdated(gmdate('Y-m-d\TH:i:s\Z'));
		$this->snapshotSource($stream);

		$this->streamService->updateStream($stream);

		$updated = $this->streamService->getStreamByNid($nid);
		$updated->addInstancePath(
			new InstancePath(
				$actor->getId(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
			)
		);

		try {
			$this->activityService->updateActivity($actor, $updated);
		} catch (\Exception $e) {
			$this->logger->warning('Failed to federate post update', ['exception' => $e]);
		}

		return $updated;
	}

	/**
	 * The stored wire object is what a reload reads the language and the edit
	 * stamp from, and what a remote Update rewrites; a local edit has to
	 * rewrite it the same way or the next re-export federates the old text.
	 */
	private function snapshotSource(Stream $stream): void {
		$stream->setSource(json_encode($stream, JSON_UNESCAPED_SLASHES));
	}

	/**
	 * The language a post is written in: what the client said, else what the
	 * post already had, else the poster's default.
	 */
	private function languageFor(string $requested, Person $actor, string $current = ''): string {
		$requested = Stream::normalizeLanguage($requested);
		if ($requested !== '') {
			return $requested;
		}
		if ($current !== '') {
			return $current;
		}

		return $this->defaultLanguageOf($actor);
	}

	/**
	 * The poster's Nextcloud language, which is the best guess this app has
	 * for the language they write in. `IFactory::getUserLanguage()` already
	 * falls back to the instance default and then to English; a locale's
	 * region is dropped unless it names a different written language, so
	 * Mastodon's per-language filters — keyed by plain `de`, not `de-DE` —
	 * still match the post.
	 */
	private function defaultLanguageOf(Person $actor): string {
		$user = ($actor->getUserId() !== '') ? $this->userManager->get($actor->getUserId()) : null;
		$language = Stream::normalizeLanguage($this->l10nFactory->getUserLanguage($user));
		if ($language === '') {
			return 'en';
		}

		$primary = explode('-', $language, 2)[0];

		return in_array($primary, self::REGIONAL_LANGUAGES, true) ? $language : $primary;
	}

	/**
	 * @param Post $post
	 */
	public function fixRecipientAndHashtags(Post $post) {
		preg_match_all('/(?!\b)@([^\s]+)/', $post->getContent(), $matchesTo);
		preg_match_all('/(?!\b)#([^\s]+)/', $post->getContent(), $matchesHash);

		foreach ($matchesTo[1] as $to) {
			$post->addTo($to);
		}

		foreach ($matchesHash[1] as $hash) {
			$post->addHashtag($hash);
		}
	}
}
