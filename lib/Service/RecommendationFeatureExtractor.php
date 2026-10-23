<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Tools\SearchTerms;

/** Deterministic, local-only features used to retrieve recommendation candidates. */
final class RecommendationFeatureExtractor {
	public const VERSION = 2;
	private const MAX_CONTENT_CHARS = 20000;
	private const MAX_TERMS = 500;

	public function __construct(
		private RecommendationTopicCatalog $catalog,
	) {
	}

	/**
	 * Extract broad topic and format features without inferring a reader profile.
	 * Topics marked sensitive are deliberately omitted until a separate reviewed
	 * policy allows their use in recommendation ranking.
	 *
	 * @return array{version: int, topics: array<string, array{score: float, evidence: string}>, format: string, language: string}
	 */
	public function extract(Stream $post): array {
		$bodyTerms = self::contentTerms(mb_substr($post->getContent(), 0, self::MAX_CONTENT_CHARS, 'UTF-8'));
		$hashtagTerms = array_map(self::normaliseHashtag(...), $post->getHashtags());
		$matches = [];

		foreach ($this->catalog->all() as $topic) {
			if (in_array('sensitive-topic', $topic['policyTags'], true)) {
				continue;
			}
			foreach ($topic['aliases'] as $alias) {
				$aliasTerms = SearchTerms::ofText($alias, 20, true);
				if ($aliasTerms === []) {
					continue;
				}
				if (self::containsSequence($bodyTerms, $aliasTerms)) {
					$matches[$topic['id']] = ['score' => 0.72, 'evidence' => 'text'];
					break;
				}
				$aliasKey = self::normaliseHashtag($alias);
				if ($aliasKey !== '' && in_array($aliasKey, $hashtagTerms, true)) {
					$matches[$topic['id']] = ['score' => 1.0, 'evidence' => 'hashtag'];
					break;
				}
			}
		}

		// A known child also supplies its non-sensitive parents, while keeping
		// the evidence source and score attached to each feature.
		foreach (array_keys($matches) as $topicId) {
			$parent = $this->catalog->parentOf($topicId);
			while ($parent !== null) {
				$parentTopic = $this->topic($parent);
				if ($parentTopic === null || in_array('sensitive-topic', $parentTopic['policyTags'], true)) {
					break;
				}
				$matches[$parent] ??= $matches[$topicId];
				$parent = $this->catalog->parentOf($parent);
			}
		}

		return [
			'version' => self::VERSION,
			'topics' => $matches,
			'format' => self::formatOf($post),
			'language' => strtolower(trim($post->getLanguage())),
		];
	}

	/** @param list<string> $haystack @param list<string> $needle */
	private static function containsSequence(array $haystack, array $needle): bool {
		$length = count($needle);
		if ($length === 0 || $length > count($haystack)) {
			return false;
		}
		for ($offset = 0, $end = count($haystack) - $length; $offset <= $end; $offset++) {
			if (array_slice($haystack, $offset, $length) === $needle) {
				return true;
			}
		}

		return false;
	}

	/** @return list<string> */
	private static function contentTerms(string $html): array {
		$text = ACore::withoutMarkup(str_replace('<', ' <', $html));
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
		$text = mb_strtolower(mb_scrub($text, 'UTF-8'), 'UTF-8');
		$words = preg_split('/[^\p{L}\p{N}\p{M}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
		$terms = [];
		foreach (($words === false) ? [] : $words as $word) {
			if (mb_strlen($word, 'UTF-8') < SearchTerms::MIN_LENGTH) {
				continue;
			}
			$terms[] = mb_substr($word, 0, SearchTerms::MAX_LENGTH, 'UTF-8');
			if (count($terms) >= self::MAX_TERMS) {
				break;
			}
		}

		return $terms;
	}

	private static function normaliseHashtag(string $value): string {
		$value = mb_strtolower(ltrim(trim($value), '#'), 'UTF-8');

		return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
	}

	/** @return array{id: string, policyTags: list<string>}|null */
	private function topic(string $id): ?array {
		foreach ($this->catalog->all() as $topic) {
			if ($topic['id'] === $id) {
				return ['id' => $topic['id'], 'policyTags' => $topic['policyTags']];
			}
		}

		return null;
	}

	private static function formatOf(Stream $post): string {
		$short = Stream::shortKindOf((string)json_encode(array_map(
			static fn (MediaAttachment $attachment): array => $attachment->asLocal(),
			$post->getAttachments()
		)));
		if ($short !== Stream::SHORT_KIND_NONE) {
			return 'short';
		}
		if ($post->isVideo()) {
			return 'video';
		}
		foreach ($post->getAttachments() as $attachment) {
			if ($attachment->getType() === 'image') {
				return 'image';
			}
			if ($attachment->getType() === 'audio') {
				return 'audio';
			}
		}

		return $post instanceof Question ? 'poll' : 'text';
	}
}
