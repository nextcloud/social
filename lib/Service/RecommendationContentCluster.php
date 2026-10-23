<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

/** Builds a local, non-sensitive signature for scoped post-similarity feedback. */
final class RecommendationContentCluster {
	/** @param list<string> $topicIds */
	public static function key(array $topicIds, string $format, RecommendationTopicCatalog $catalog): ?string {
		if (!in_array($format, ['audio', 'image', 'poll', 'short', 'text', 'video'], true)) {
			return null;
		}

		$topics = [];
		foreach (array_unique($topicIds) as $topicId) {
			if (!is_string($topicId)) {
				continue;
			}
			$topic = $catalog->find($topicId);
			if ($topic !== null && !in_array('sensitive-topic', $topic['policyTags'], true)) {
				$topics[] = $topicId;
			}
		}
		if ($topics === []) {
			return null;
		}

		sort($topics, SORT_STRING);
		return hash('sha256', "recommendation-content-v1\0" . $format . "\0" . implode("\0", $topics));
	}
}
