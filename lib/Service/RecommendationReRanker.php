<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

/** Selects a deterministic page while applying relaxable author/topic diversity. */
final class RecommendationReRanker {
	private const WINDOW_SIZE = 20;
	private const MAX_AUTHOR_IN_WINDOW = 2;
	private const MAX_TOPIC_IN_WINDOW = 9;
	private const LOOKAHEAD = 50;

	/**
	 * @param list<array<string, mixed>> $rankedCandidates
	 * @param list<string> $prioritisedTopics
	 * @return list<array<string, mixed>>
	 */
	public function page(array $rankedCandidates, int $pageSize = 20, array $prioritisedTopics = []): array {
		$pageSize = max(0, min(100, $pageSize));
		$remaining = [];
		$seen = [];
		foreach ($rankedCandidates as $candidate) {
			if (($candidate['eligible'] ?? true) !== true || !isset($candidate['nid'])) {
				continue;
			}
			$key = (string)($candidate['canonical_key'] ?? $candidate['nid']);
			if ($key === '' || isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$remaining[] = $candidate;
		}

		$selected = [];
		while ($remaining !== [] && count($selected) < $pageSize) {
			$window = array_slice($remaining, 0, self::LOOKAHEAD);
			$bestIndex = 0;
			$bestAdjusted = -INF;
			foreach ($window as $index => $candidate) {
				$author = (string)($candidate['author_key'] ?? '');
				$rawTopics = $candidate['topic_ids'] ?? [];
				$topics = is_array($rawTopics)
					? array_values(array_unique(array_filter($rawTopics, static fn (mixed $topic): bool => is_string($topic) && $topic !== '')))
					: [];
				$penalty = 0.0;
				if ($author !== '') {
					$recentAuthorCount = self::recentCount($selected, $author, 'author_key');
					$penalty += max(0.0, (float)($recentAuthorCount - self::MAX_AUTHOR_IN_WINDOW + 1)) * 0.08;
				}
				foreach ($topics as $topic) {
					if (in_array($topic, $prioritisedTopics, true)) {
						continue;
					}
					$recentTopicCount = self::recentTopicCount($selected, $topic);
					$penalty += max(0.0, (float)($recentTopicCount - self::MAX_TOPIC_IN_WINDOW + 1)) * 0.04;
				}
				$adjusted = (float)($candidate['rank_score'] ?? 0.0) - $penalty;
				if ($adjusted > $bestAdjusted) {
					$bestAdjusted = $adjusted;
					$bestIndex = $index;
				}
			}

			$chosen = $remaining[$bestIndex];
			array_splice($remaining, $bestIndex, 1);
			$selected[] = $chosen;
		}

		return $selected;
	}

	/** @param list<array<string, mixed>> $selected */
	private static function recentCount(array $selected, string $value, string $field): int {
		$recent = array_slice($selected, -self::WINDOW_SIZE);
		$count = 0;
		foreach ($recent as $candidate) {
			if (($candidate[$field] ?? '') === $value) {
				$count++;
			}
		}

		return $count;
	}

	/** @param list<array<string, mixed>> $selected */
	private static function recentTopicCount(array $selected, string $topic): int {
		$recent = array_slice($selected, -self::WINDOW_SIZE);
		$count = 0;
		foreach ($recent as $candidate) {
			if (in_array($topic, $candidate['topic_ids'] ?? [], true)) {
				$count++;
			}
		}

		return $count;
	}
}
