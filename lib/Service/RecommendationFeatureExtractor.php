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
	public const VERSION = 3;
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
		$bodySegments = self::contentSegments(mb_substr($post->getContent(), 0, self::MAX_CONTENT_CHARS, 'UTF-8'));
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
				if (self::containsAlias($topic['id'], $alias, $aliasTerms, $bodySegments, $topic['aliases'])) {
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

	/** @param list<string> $aliasTerms @param list<list<string>> $segments @param list<string> $aliases */
	private static function containsAlias(string $topicId, string $alias, array $aliasTerms, array $segments, array $aliases): bool {
		foreach ($segments as $terms) {
			if (!self::containsSequence($terms, $aliasTerms)) {
				continue;
			}
			if ($topicId !== 'tech.apple' || $alias !== 'apple' || self::hasAppleTechnologyContext($terms, $aliases)) {
				return true;
			}
		}

		return false;
	}

	/** @param list<string> $terms @param list<string> $aliases */
	private static function hasAppleTechnologyContext(array $terms, array $aliases): bool {
		$contextAliases = array_merge($aliases, ['macos', 'macbook', 'ios', 'app store', 'tim cook', 'computer', 'laptop', 'smartphone', 'device']);
		foreach ($contextAliases as $alias) {
			if ($alias === 'apple') {
				continue;
			}
			$aliasTerms = SearchTerms::ofText($alias, 20, true);
			if ($aliasTerms !== [] && self::containsSequence($terms, $aliasTerms)) {
				return true;
			}
		}

		return false;
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

	/** @return list<list<string>> */
	private static function contentSegments(string $html): array {
		$html = preg_replace('#```[\\s\\S]*?```|~~~[\\s\\S]*?~~~|`[^`\\r\\n]*`#u', ' ', $html) ?? $html;
		$html = preg_replace('/^[\\t ]{0,3}>.*(?:\\R|$)/mu', ' ', $html) ?? $html;
		$html = preg_replace('#<(blockquote|pre|code)\\b[^>]*>.*?</\\1\\s*>#isu', ' ', $html) ?? $html;
		$text = ACore::withoutMarkup(str_replace('<', ' <', $html));
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
		$text = mb_strtolower(mb_scrub($text, 'UTF-8'), 'UTF-8');
		$sentences = preg_split('/(?<=[.!?。！？])\s+|[\r\n]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
		$segments = [];
		$termCount = 0;
		foreach (($sentences === false) ? [] : $sentences as $sentence) {
			$words = preg_split('/[^\p{L}\p{N}\p{M}]+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY);
			$terms = [];
			foreach (($words === false) ? [] : $words as $word) {
				if (mb_strlen($word, 'UTF-8') < SearchTerms::MIN_LENGTH) {
					continue;
				}
				$terms[] = mb_substr($word, 0, SearchTerms::MAX_LENGTH, 'UTF-8');
				$termCount++;
				if ($termCount >= self::MAX_TERMS) {
					break 2;
				}
			}
			if ($terms !== []) {
				$segments[] = $terms;
			}
		}

		return $segments;
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
