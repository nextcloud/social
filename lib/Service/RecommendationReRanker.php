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
	private const MAX_TOPIC_IN_WINDOW = 3;
	private const MAX_STORY_IN_WINDOW = 2;
	private const TOPIC_CONCENTRATION_COST = 0.18;
	private const LOOKAHEAD = 100;
	private const DEFAULT_EXPLORATION_INTERVAL = 10;
	private const EXPLORATION_MIN_RANK_DISTANCE = 4;
	private const EXPLORATION_MIN_TOPIC_MATCH = 0.65;
	private const EXPLORATION_MIN_NOVELTY = 0.75;
	private const EXPLORATION_MAX_EXPOSURE = 0.25;
	private const EXPLORATION_MAX_SCORE_DROP = 0.20;

	/**
	 * @param list<array<string, mixed>> $rankedCandidates
	 * @return list<array<string, mixed>>
	 */
	public function page(array $rankedCandidates, int $pageSize = 20, ?string $sessionSeed = null, string $contentBalance = 'balanced'): array {
		$pageSize = max(0, min(100, $pageSize));
		$explorationInterval = match ($contentBalance) {
			'following' => self::DEFAULT_EXPLORATION_INTERVAL * 2,
			'discovery' => max(1, intdiv(self::DEFAULT_EXPLORATION_INTERVAL, 2)),
			default => self::DEFAULT_EXPLORATION_INTERVAL,
		};
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
			$candidate['_base_position'] = count($remaining);
			$remaining[] = $candidate;
		}
		$sessionSeed ??= hash('sha256', implode("\0", array_keys($seen)));

		$selected = [];
		while ($remaining !== [] && count($selected) < $pageSize) {
			$window = array_slice($remaining, 0, self::LOOKAHEAD);
			$saturatedTopics = array_fill_keys(self::saturatedTopics($selected), true);
			$hasUnsaturatedAlternative = false;
			foreach ($window as $candidate) {
				if (!self::hasSaturatedTopic(self::topicIds($candidate), $saturatedTopics)
					&& !self::hasSaturatedStory($candidate, $selected)) {
					$hasUnsaturatedAlternative = true;
					break;
				}
			}
			$bestIndex = 0;
			$bestAdjusted = -INF;
			$adjustedScores = [];
			foreach ($window as $index => $candidate) {
				if ($hasUnsaturatedAlternative && (self::hasSaturatedTopic(self::topicIds($candidate), $saturatedTopics)
					|| self::hasSaturatedStory($candidate, $selected))) {
					continue;
				}
				$author = (string)($candidate['author_key'] ?? '');
				$topics = self::topicIds($candidate);
				$penalty = 0.0;
				if ($author !== '') {
					$recentAuthorCount = self::recentCount($selected, $author, 'author_key');
					$penalty += max(0.0, (float)($recentAuthorCount - self::MAX_AUTHOR_IN_WINDOW + 1)) * 0.08;
				}
				foreach ($topics as $topic) {
					$recentTopicCount = self::recentTopicCount($selected, $topic);
					$penalty += max(0.0, (float)($recentTopicCount - self::MAX_TOPIC_IN_WINDOW + 1)) * self::TOPIC_CONCENTRATION_COST;
				}
				$adjusted = (float)($candidate['rank_score'] ?? 0.0) - $penalty;
				$adjustedScores[$index] = $adjusted;
				if ($adjusted > $bestAdjusted) {
					$bestAdjusted = $adjusted;
					$bestIndex = $index;
				}
			}
			$slot = count($selected) + 1;
			$wasExploration = false;
			if ($slot % $explorationInterval === 0) {
				$explorationIndex = self::explorationCandidate($window, $adjustedScores, $bestAdjusted, $slot, $sessionSeed);
				if ($explorationIndex !== null) {
					$bestIndex = $explorationIndex;
					$wasExploration = true;
				}
			}

			$chosen = $remaining[$bestIndex];
			if ($wasExploration) {
				$chosen['_exploration_slot'] = true;
			}
			$chosen['_promoted_by_diversity'] = !$wasExploration && $bestIndex > 0;
			unset($chosen['_base_position']);
			array_splice($remaining, $bestIndex, 1);
			$selected[] = $chosen;
		}

		return $selected;
	}

	/** @param list<array<string, mixed>> $window @param array<int, float> $adjustedScores */
	private static function explorationCandidate(array $window, array $adjustedScores, float $bestAdjusted, int $slot, string $sessionSeed): ?int {
		$eligible = [];
		foreach ($window as $index => $candidate) {
			if (!self::qualifiesForExploration($candidate, $adjustedScores[$index] ?? -INF, $bestAdjusted, $slot)) {
				continue;
			}
			$key = (string)($candidate['canonical_key'] ?? $candidate['nid'] ?? '');
			$eligible[$index] = hash('sha256', $sessionSeed . "\0" . $key);
		}
		if ($eligible === []) {
			return null;
		}
		asort($eligible, SORT_STRING);
		return array_key_first($eligible);
	}

	/** @param array<string, mixed> $candidate */
	private static function qualifiesForExploration(array $candidate, float $adjusted, float $bestAdjusted, int $slot): bool {
		$number = static fn (mixed $value): float => is_numeric($value) && is_finite((float)$value) ? (float)$value : 0.0;
		$matchingTopics = $candidate['matching_topic_ids'] ?? [];
		return ($candidate['_base_position'] ?? PHP_INT_MAX) >= $slot - 1 + self::EXPLORATION_MIN_RANK_DISTANCE
			&& is_array($matchingTopics) && $matchingTopics !== []
			&& $number($candidate['is_followed'] ?? 1.0) === 0.0
			&& $number($candidate['topic_match'] ?? 0.0) >= self::EXPLORATION_MIN_TOPIC_MATCH
			&& $number($candidate['novelty'] ?? 0.0) >= self::EXPLORATION_MIN_NOVELTY
			&& $number($candidate['exposure_penalty'] ?? 1.0) <= self::EXPLORATION_MAX_EXPOSURE
			&& $adjusted >= $bestAdjusted - self::EXPLORATION_MAX_SCORE_DROP;
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
			if (in_array($topic, self::topicIds($candidate), true)) {
				$count++;
			}
		}

		return $count;
	}

	/** @param list<array<string, mixed>> $selected @return list<string> */
	private static function saturatedTopics(array $selected): array {
		$counts = [];
		foreach (array_slice($selected, -self::WINDOW_SIZE) as $candidate) {
			foreach (self::topicIds($candidate) as $topic) {
				$counts[$topic] = ($counts[$topic] ?? 0) + 1;
			}
		}

		return array_keys(array_filter($counts, static fn (int $count): bool => $count >= self::MAX_TOPIC_IN_WINDOW));
	}

	/** @param list<string> $topics @param array<string, true> $saturated */
	private static function hasSaturatedTopic(array $topics, array $saturated): bool {
		foreach ($topics as $topic) {
			if (isset($saturated[$topic])) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string, mixed> $candidate @param list<array<string, mixed>> $selected */
	private static function hasSaturatedStory(array $candidate, array $selected): bool {
		$terms = $candidate['story_terms'] ?? [];
		if (!is_array($terms) || $terms === []) {
			return false;
		}

		$similar = 0;
		foreach (array_slice($selected, -self::WINDOW_SIZE) as $selectedCandidate) {
			$selectedTerms = $selectedCandidate['story_terms'] ?? [];
			if (is_array($selectedTerms) && RecommendationStorySimilarity::areSimilar($terms, $selectedTerms)) {
				$similar++;
				if ($similar >= self::MAX_STORY_IN_WINDOW) {
					return true;
				}
			}
		}

		return false;
	}

	/** @param array<string, mixed> $candidate @return list<string> */
	private static function topicIds(array $candidate): array {
		$raw = $candidate['diversity_topics'] ?? null;
		if (!is_array($raw) || $raw === []) {
			$raw = $candidate['topic_ids'] ?? [];
		}

		return is_array($raw)
			? array_values(array_unique(array_filter($raw, static fn (mixed $topic): bool => is_string($topic) && $topic !== '')))
			: [];
	}
}
