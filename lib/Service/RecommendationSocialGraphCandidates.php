<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Stream;

/** Bounded second-degree recall from the viewer's locally cached follow graph. */
final class RecommendationSocialGraphCandidates {
	public const MAX_CANDIDATES = 10;
	private const SCAN_MULTIPLIER = 5;

	public function __construct(
		private StreamRequest $streams,
		private RecommendationFeatureExtractor $features,
	) {
	}

	/**
	 * @param list<string> $excludeNids
	 * @param list<string> $languages
	 * @param list<string> $topicIds
	 * @param list<string> $hiddenTopicIds
	 * @return array<string, Stream> by nid
	 */
	public function matching(
		string $sinceNid,
		int $limit,
		array $excludeNids,
		array $languages,
		array $topicIds,
		array $hiddenTopicIds,
		string $media = '',
	): array {
		$limit = max(0, min(self::MAX_CANDIDATES, $limit));
		if ($limit === 0 || $topicIds === []) {
			return [];
		}

		$scanLimit = min(100, $limit * self::SCAN_MULTIPLIER);
		$posts = $this->streams->recommendationSocialGraphCandidates($sinceNid, $scanLimit, $excludeNids, $languages);
		$result = [];
		foreach ($posts as $nid => $post) {
			$features = $this->features->extract($post);
			$matchedTopics = array_keys($features['topics']);
			if (array_intersect($topicIds, $matchedTopics) === []
				|| array_intersect($hiddenTopicIds, $matchedTopics) !== []) {
				continue;
			}
			if ($languages !== [] && $features['language'] !== '' && !in_array($features['language'], $languages, true)) {
				continue;
			}
			if (!self::matchesMedia($features['format'], $media)) {
				continue;
			}

			$result[(string)$nid] = $post;
			if (count($result) >= $limit) {
				break;
			}
		}

		return $result;
	}

	private static function matchesMedia(string $format, string $media): bool {
		return match ($media) {
			'photos' => $format === 'image',
			'videos' => in_array($format, ['video', 'short'], true),
			'shorts' => $format === 'short',
			'media' => in_array($format, ['image', 'video', 'short'], true),
			default => true,
		};
	}
}
