<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use InvalidArgumentException;

/**
 * Runs deterministic, synthetic offline comparisons without recording reader
 * activity or changing serving weights.
 */
final class RecommendationOfflineEvaluator {
	private const PAGE_SIZE = 20;
	private const BOOTSTRAP_SAMPLES = 2000;
	private const BOOTSTRAP_SEED = 4040;
	private const TIMING_REPEATS = 25;

	public function __construct(
		private RecommendationRanker $ranker,
		private RecommendationReRanker $reRanker,
	) {
	}

	/**
	 * @param array{name: string, notice?: string, profiles: list<array<string, mixed>>, posts: list<array<string, mixed>>} $dataset
	 * @return array<string, mixed>
	 */
	public function evaluate(array $dataset): array {
		$this->validateDataset($dataset);
		$profileResults = [];
		$variantTimes = [];
		$sourceTotals = ['all_relevant' => 0, 'retrieved_relevant' => 0, 'eligible' => 0];
		$protocolTotals = [];
		$failureBreakdown = [
			'eligible_and_available' => 0,
			'non_public' => 0,
			'not_discoverable' => 0,
			'deleted_or_not_visible' => 0,
			'explicitly_hidden_topic' => 0,
			'language_excluded' => 0,
			'source_unavailable' => 0,
		];
		$corpusKeys = [];
		foreach ($dataset['posts'] as $post) {
			$corpusKeys[(string)($post['canonical_key'] ?? $post['id'])] = true;
		}
		$corpusUniqueCount = count($corpusKeys);

		foreach ($dataset['profiles'] as $profile) {
			foreach ($dataset['posts'] as $post) {
				$reason = $this->controlFailureReason($profile, $post);
				if ($reason === null && ($post['available'] ?? true) !== true) {
					$reason = 'source_unavailable';
				} elseif ($reason === null) {
					$reason = 'eligible_and_available';
				}
				$failureBreakdown[$reason]++;
			}
			$candidates = $this->candidatesFor($profile, $dataset['posts']);
			$candidateKeys = [];
			foreach ($candidates as $candidate) {
				$candidateKeys[(string)$candidate['canonical_key']] = true;
			}
			$sourceTotals['eligible'] += count($candidateKeys);
			$relevantKeys = [];
			$protocolRelevantKeys = [];
			foreach ($dataset['posts'] as $post) {
				$grade = $this->gradeFor($profile, $post);
				if ($grade > 0 && $this->isEligibleForEvaluation($profile, $post)) {
					$key = (string)($post['canonical_key'] ?? $post['id']);
					$relevantKeys[$key] = ($relevantKeys[$key] ?? false) || ($post['available'] ?? true) === true;
					$protocol = (string)($post['protocol'] ?? 'unknown');
					$protocolRelevantKeys[$protocol][$key] = ($protocolRelevantKeys[$protocol][$key] ?? false) || ($post['available'] ?? true) === true;
				}
			}
			foreach ($relevantKeys as $available) {
				$sourceTotals['all_relevant']++;
				$sourceTotals['retrieved_relevant'] += $available ? 1 : 0;
			}
			foreach ($protocolRelevantKeys as $protocol => $keys) {
				foreach ($keys as $available) {
					$protocolTotals[$protocol]['all_relevant'] = ($protocolTotals[$protocol]['all_relevant'] ?? 0) + 1;
					$protocolTotals[$protocol]['retrieved_relevant'] = ($protocolTotals[$protocol]['retrieved_relevant'] ?? 0) + ($available ? 1 : 0);
				}
			}

			$variantOrders = $this->rankVariants($profile, array_values($candidates));
			$perVariant = [];
			foreach ($variantOrders as $variant => $ordered) {
				$page = array_slice($ordered, 0, self::PAGE_SIZE);
				$perVariant[$variant] = $this->metrics($profile, $page, array_values($candidates), count($candidateKeys), $corpusUniqueCount);
				$variantTimes[$variant][] = $this->measure($profile, array_values($candidates), $variant);
			}
			$profileResults[(string)$profile['id']] = $perVariant;
		}

		$variantNames = array_keys(reset($profileResults));
		$summary = [];
		foreach ($variantNames as $variant) {
			$metrics = array_map(static fn (array $result): array => $result[$variant], array_values($profileResults));
			$summary[$variant] = [
				'ndcg_at_20' => self::mean(array_column($metrics, 'ndcg_at_20')),
				'returned_relevant_at_20' => self::mean(array_column($metrics, 'returned_relevant')),
				'topic_diversity_unique_at_20' => self::mean(array_column($metrics, 'unique_topics')),
				'topic_diversity_effective_count_at_20' => self::mean(array_column($metrics, 'effective_topic_count')),
				'hard_control_compliance' => self::mean(array_column($metrics, 'hard_control_compliance')),
				'hard_control_violations_mean' => self::mean(array_column($metrics, 'hard_control_violations')),
				'duplicate_rate' => self::mean(array_column($metrics, 'duplicate_rate')),
				'candidate_count_mean' => self::mean(array_column($metrics, 'candidate_count')),
				'candidate_coverage_mean' => self::mean(array_column($metrics, 'candidate_coverage')),
				'local_runtime_ms' => self::percentiles($variantTimes[$variant] ?? []),
				'ndcg_delta_vs_topic_match_95_percentile_interval' => $variant === 'topic_match'
					? ['low' => 0.0, 'high' => 0.0]
					: $this->bootstrapDelta($profileResults, $variant, 'topic_match'),
			];
		}

		return [
			'schema_version' => 1,
			'dataset' => [
				'name' => $dataset['name'],
				'notice' => $dataset['notice'] ?? 'Synthetic scenario labels are exploratory and are not evidence of real-user satisfaction.',
				'profile_count' => count($dataset['profiles']),
				'corpus_record_count' => count($dataset['posts']),
				'corpus_unique_post_count' => $corpusUniqueCount,
				'eligibility' => [
					'eligible_candidate_count_mean' => (float)$sourceTotals['eligible'] / (float)count($dataset['profiles']),
					'candidate_coverage_mean' => ((float)$sourceTotals['eligible'] / (float)count($dataset['profiles'])) / (float)max(1, $corpusUniqueCount),
					'source_recall' => $sourceTotals['all_relevant'] === 0 ? null : $sourceTotals['retrieved_relevant'] / $sourceTotals['all_relevant'],
					'source_recall_by_protocol' => array_map(static fn (array $totals): ?float => ($totals['all_relevant'] ?? 0) === 0 ? null : ($totals['retrieved_relevant'] ?? 0) / $totals['all_relevant'], $protocolTotals),
					'relevant_items_in_judged_corpus' => $sourceTotals['all_relevant'],
					'relevant_items_available_to_profiles' => $sourceTotals['retrieved_relevant'],
					'failure_breakdown' => [
						'basis' => 'primary exclusion reason per profile-post record; categories are mutually exclusive and contain counts only',
						'counts' => $failureBreakdown,
						'profile_post_record_count' => array_sum($failureBreakdown),
					],
				],
			],
			'methodology' => [
				'page_size' => self::PAGE_SIZE,
				'uncertainty' => 'Profile-cluster bootstrap percentile interval; descriptive only for synthetic fixtures.',
				'runtime' => 'Local evaluator ranking time only; not an application serving latency benchmark.',
				'weights_promoted' => false,
			],
			'variants' => $summary,
			'persona_scenarios' => array_map(static fn (array $profile): array => [
				'id' => $profile['id'],
				'scenario' => $profile['scenario'] ?? '',
				'followed_author_count' => $profile['followed_author_count'] ?? count($profile['followed_authors'] ?? []),
			], $dataset['profiles']),
			'profiles' => $profileResults,
		];
	}

	/** @param array<string, mixed> $profile @param list<array<string, mixed>> $posts @return array<string, array<string, mixed>> */
	private function candidatesFor(array $profile, array $posts): array {
		$candidates = [];
		$followed = array_fill_keys($profile['followed_authors'] ?? [], true);
		$grades = $profile['topic_grades'] ?? [];
		foreach ($posts as $post) {
			$topics = $post['topics'] ?? [];
			if (!$this->isEligibleForEvaluation($profile, $post) || ($post['available'] ?? true) !== true) {
				continue;
			}
			$grade = $this->topicFeatureGradeFor($profile, $post);
			$judgementGrade = $this->gradeFor($profile, $post);
			$topicMatch = match (true) {
				$grade >= 3 => 1.0,
				$grade === 2 => 0.66,
				$grade === 1 => 0.33,
				default => 0.0,
			};
			$isFollowed = isset($followed[$post['author'] ?? '']);
			$age = max(0.0, (float)($post['age_hours'] ?? 24.0));
			$legacyMatch = array_intersect($topics, $post['legacy_hashtag_topics'] ?? []);
			$candidates[(string)$post['id']] = array_merge($post, [
				'nid' => (string)$post['id'],
				'canonical_key' => (string)($post['canonical_key'] ?? $post['id']),
				'author_key' => (string)($post['author'] ?? ''),
				'eligible' => true,
				'topic_match' => $topicMatch,
				'matching_topic_ids' => array_values(array_intersect($topics, array_keys($grades))),
				'is_followed' => $isFollowed ? 1.0 : 0.0,
				'author_affinity' => $isFollowed ? 1.0 : 0.0,
				'freshness' => exp(-$age / 36.0),
				'usefulness' => 0.5,
				'format_match' => in_array($post['format'] ?? '', $profile['preferred_formats'] ?? [], true) ? 0.7 : 0.0,
				'novelty' => $isFollowed ? 0.0 : 0.8,
				'legacy_match' => $legacyMatch !== [],
				'legacy_score' => (float)($post['legacy_score'] ?? 0.0),
				'age_hours' => $age,
				'grade' => $judgementGrade,
			]);
		}
		return $candidates;
	}

	/** @param array<string, mixed> $profile @param array<string, mixed> $post */
	private function isEligibleForEvaluation(array $profile, array $post): bool {
		return $this->controlFailureReason($profile, $post) === null;
	}

	/** @param array<string, mixed> $profile @param array<string, mixed> $post */
	private function controlFailureReason(array $profile, array $post): ?string {
		$hidden = $profile['hidden_topics'] ?? [];
		$languages = $profile['languages'] ?? [];
		if (($post['public'] ?? false) !== true) {
			return 'non_public';
		}
		if (($post['discoverable'] ?? false) !== true) {
			return 'not_discoverable';
		}
		if (($post['deleted'] ?? false) !== false || ($post['user_visible'] ?? false) !== true) {
			return 'deleted_or_not_visible';
		}
		if (array_intersect($post['topics'] ?? [], $hidden) !== []) {
			return 'explicitly_hidden_topic';
		}
		if ($languages !== [] && !in_array($post['language'] ?? '', $languages, true)) {
			return 'language_excluded';
		}
		return null;
	}

	/** @param array<string, mixed> $profile @param array<string, mixed> $post */
	private function gradeFor(array $profile, array $post): int {
		$overrides = $post['judgement_overrides'] ?? [];
		if (isset($overrides[$profile['id']]) && is_int($overrides[$profile['id']])) {
			return min(3, max(0, $overrides[$profile['id']]));
		}
		return $this->topicGradeMap($profile, $post, 'judgement_topic_grades');
	}

	/** @param array<string, mixed> $profile @param array<string, mixed> $post */
	private function topicFeatureGradeFor(array $profile, array $post): int {
		return $this->topicGradeMap($profile, $post, 'topic_grades');
	}

	/** @param array<string, mixed> $profile @param array<string, mixed> $post */
	private function topicGradeMap(array $profile, array $post, string $map): int {
		$grade = 0;
		foreach ($post['topics'] ?? [] as $topic) {
			$value = $profile[$map][$topic] ?? 0;
			if (is_int($value)) {
				$grade = max($grade, min(3, max(0, $value)));
			}
		}
		return $grade;
	}

	/** @param array<string, mixed> $profile @param list<array<string, mixed>> $candidates @return array<string, list<array<string, mixed>>> */
	private function rankVariants(array $profile, array $candidates): array {
		return [
			'legacy_hashtag' => $this->rankVariant($profile, $candidates, 'legacy_hashtag'),
			'chronological_following' => $this->rankVariant($profile, $candidates, 'chronological_following'),
			'topic_match' => $this->rankVariant($profile, $candidates, 'topic_match'),
			'topic_plus_follow' => $this->rankVariant($profile, $candidates, 'topic_plus_follow'),
			'h0_ranker' => $this->rankVariant($profile, $candidates, 'h0_ranker'),
			'h0_plus_rerank' => $this->rankVariant($profile, $candidates, 'h0_plus_rerank'),
			'h0_following' => $this->rankVariant($profile, $candidates, 'h0_following'),
			'h0_following_plus_rerank' => $this->rankVariant($profile, $candidates, 'h0_following_plus_rerank'),
			'h0_discovery' => $this->rankVariant($profile, $candidates, 'h0_discovery'),
			'h0_discovery_plus_rerank' => $this->rankVariant($profile, $candidates, 'h0_discovery_plus_rerank'),
		];
	}

	/** @param array<string, mixed> $profile @param list<array<string, mixed>> $page @param list<array<string, mixed>> $candidates @return array<string, float|int> */
	private function metrics(array $profile, array $page, array $candidates, int $candidateCount, int $corpusCount): array {
		$metricPage = $this->dedupePage($page);
		$dcg = 0.0;
		foreach ($metricPage as $position => $post) {
			$grade = (int)($post['grade'] ?? 0);
			$dcg += (float)(2 ** $grade - 1) / log((float)((int)$position + 2), 2);
		}
		$idealByCanonical = [];
		foreach ($candidates as $candidate) {
			$key = (string)($candidate['canonical_key'] ?? $candidate['id']);
			if (!isset($idealByCanonical[$key]) || $candidate['grade'] > $idealByCanonical[$key]['grade']) {
				$idealByCanonical[$key] = $candidate;
			}
		}
		$ideal = array_values($idealByCanonical);
		usort($ideal, static fn (array $left, array $right): int => ($right['grade'] <=> $left['grade']) ?: strcmp((string)$left['id'], (string)$right['id']));
		$idcg = 0.0;
		foreach (array_slice($ideal, 0, self::PAGE_SIZE) as $position => $post) {
			$idcg += (float)(2 ** (int)$post['grade'] - 1) / log((float)($position + 2), 2);
		}
		$topics = [];
		$blocked = array_fill_keys($profile['hidden_topics'] ?? [], true);
		$languages = $profile['languages'] ?? [];
		$valid = 0;
		$violations = 0;
		$pageKeys = [];
		foreach ($page as $post) {
			$key = (string)($post['canonical_key'] ?? $post['id']);
			$pageKeys[$key] = ($pageKeys[$key] ?? 0) + 1;
		}
		foreach ($metricPage as $post) {
			$validPost = ($post['public'] ?? false) === true
				&& ($post['discoverable'] ?? false) === true
				&& ($post['deleted'] ?? false) === false
				&& ($post['user_visible'] ?? false) === true
				&& ($languages === [] || in_array($post['language'] ?? '', $languages, true))
				&& !array_intersect($post['topics'] ?? [], array_keys($blocked));
			$valid += $validPost ? 1 : 0;
			$violations += $validPost ? 0 : 1;
			foreach ($post['topics'] ?? [] as $topic) {
				$topics[$topic] = ($topics[$topic] ?? 0) + 1;
			}
		}
		$duplicateCount = array_sum(array_map(static fn (int $count): int => max(0, $count - 1), $pageKeys));
		$topicTotal = array_sum($topics);
		$entropy = 0.0;
		if ($topicTotal > 0) {
			foreach ($topics as $count) {
				$probability = (float)$count / (float)$topicTotal;
				$entropy -= $probability * log($probability);
			}
		}
		return [
			'ndcg_at_20' => $idcg === 0.0 ? 0.0 : $dcg / $idcg,
			'returned_relevant' => count(array_filter($metricPage, static fn (array $post): bool => (int)$post['grade'] > 0)),
			'unique_topics' => count($topics),
			'effective_topic_count' => exp($entropy),
			'hard_control_compliance' => $metricPage === [] ? 1.0 : $valid / count($metricPage),
			'hard_control_violations' => $violations,
			'duplicate_rate' => $page === [] ? 0.0 : $duplicateCount / count($page),
			'candidate_count' => $candidateCount,
			'candidate_coverage' => $candidateCount / max(1, $corpusCount),
		];
	}

	/** @param list<array<string, mixed>> $page @return list<array<string, mixed>> */
	private function dedupePage(array $page): array {
		$unique = [];
		$seen = [];
		foreach ($page as $post) {
			$key = (string)($post['canonical_key'] ?? $post['id']);
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$unique[] = $post;
		}
		return $unique;
	}

	/** @param array<string, array<string, array<string, float|int>>> $profiles */
	private function bootstrapDelta(array $profiles, string $variant, string $baseline): array {
		$ids = array_keys($profiles);
		if (count($ids) < 2) {
			return ['low' => 0.0, 'high' => 0.0];
		}
		$state = self::BOOTSTRAP_SEED;
		$deltas = [];
		for ($sample = 0; $sample < self::BOOTSTRAP_SAMPLES; $sample++) {
			$total = 0.0;
			for ($i = 0; $i < count($ids); $i++) {
				$state = (1664525 * $state + 1013904223) % 4294967296;
				$id = $ids[$state % count($ids)];
				$total += (float)$profiles[$id][$variant]['ndcg_at_20'] - (float)$profiles[$id][$baseline]['ndcg_at_20'];
			}
			$deltas[] = $total / (float)count($ids);
		}
		sort($deltas, SORT_NUMERIC);
		return ['low' => $deltas[(int)floor(0.025 * (float)count($deltas))], 'high' => $deltas[(int)floor(0.975 * (float)count($deltas))]];
	}

	/** @param array<string, mixed> $profile @param list<array<string, mixed>> $candidates */
	private function measure(array $profile, array $candidates, string $variant): float {
		$times = [];
		for ($repeat = 0; $repeat < self::TIMING_REPEATS; $repeat++) {
			$start = hrtime(true);
			$this->rankVariant($profile, $candidates, $variant);
			$times[] = (hrtime(true) - $start) / 1_000_000;
		}
		return self::percentiles($times)['p95'];
	}

	/** @param array<string, mixed> $profile @param list<array<string, mixed>> $candidates */
	private function rankVariant(array $profile, array $candidates, string $variant): array {
		$sortBy = static function (array $items, callable $score): array {
			usort($items, static fn (array $left, array $right): int => ($score($right) <=> $score($left)) ?: strcmp((string)$left['id'], (string)$right['id']));
			return $items;
		};
		switch ($variant) {
			case 'legacy_hashtag':
				$items = array_values(array_filter($candidates, static fn (array $post): bool => $post['legacy_match'] === true));
				if ($items === []) {
					$items = $candidates;
				}
				return $sortBy($items, static fn (array $post): float => $post['legacy_score']);
			case 'chronological_following':
				$items = array_values(array_filter($candidates, static fn (array $post): bool => $post['is_followed'] === 1.0));
				return $sortBy($items, static fn (array $post): float => -$post['age_hours']);
			case 'topic_match':
				return $sortBy($candidates, static fn (array $post): float => $post['topic_match']);
			case 'topic_plus_follow':
				return $sortBy($candidates, static fn (array $post): float => 0.75 * $post['topic_match'] + 0.25 * $post['is_followed']);
			case 'h0_ranker':
				return $this->ranker->rank($candidates);
			case 'h0_plus_rerank':
				$ranked = $this->ranker->rank($candidates);
				return $this->reRanker->page($ranked, self::PAGE_SIZE, array_keys($profile['topic_grades'] ?? []), (string)$profile['id']);
			case 'h0_following':
				return $this->ranker->rank($candidates, 'following');
			case 'h0_following_plus_rerank':
				$ranked = $this->ranker->rank($candidates, 'following');
				return $this->reRanker->page($ranked, self::PAGE_SIZE, array_keys($profile['topic_grades'] ?? []), (string)$profile['id'], 'following');
			case 'h0_discovery':
				return $this->ranker->rank($candidates, 'discovery');
			case 'h0_discovery_plus_rerank':
				$ranked = $this->ranker->rank($candidates, 'discovery');
				return $this->reRanker->page($ranked, self::PAGE_SIZE, array_keys($profile['topic_grades'] ?? []), (string)$profile['id'], 'discovery');
			default:
				return [];
		}
	}

	/** @param list<float|int> $values @return array{median: float, p95: float} */
	private static function percentiles(array $values): array {
		if ($values === []) {
			return ['median' => 0.0, 'p95' => 0.0];
		}
		sort($values, SORT_NUMERIC);
		return [
			'median' => (float)$values[(int)floor(((float)count($values) - 1.0) * 0.5)],
			'p95' => (float)$values[(int)floor(((float)count($values) - 1.0) * 0.95)],
		];
	}

	/** @param list<float|int> $values */
	private static function mean(array $values): float {
		return $values === [] ? 0.0 : (float)array_sum($values) / (float)count($values);
	}

	/** @param array<string, mixed> $dataset */
	private function validateDataset(array $dataset): void {
		if (!isset($dataset['name']) || !is_string($dataset['name']) || !isset($dataset['profiles'], $dataset['posts']) || !is_array($dataset['profiles']) || !is_array($dataset['posts']) || $dataset['profiles'] === []) {
			throw new InvalidArgumentException('Dataset must contain a name, profiles, and posts.');
		}
		foreach ($dataset['profiles'] as $profile) {
			if (!is_array($profile) || !isset($profile['id'], $profile['topic_grades'], $profile['judgement_topic_grades']) || !is_string($profile['id']) || !is_array($profile['topic_grades']) || !is_array($profile['judgement_topic_grades'])) {
				throw new InvalidArgumentException('Every profile needs an id, topic_grades map, and independent judgement_topic_grades map.');
			}
		}
		foreach ($dataset['posts'] as $post) {
			if (!is_array($post) || !isset($post['id'], $post['topics']) || !is_string($post['id']) || !is_array($post['topics'])) {
				throw new InvalidArgumentException('Every post needs a string id and topic list.');
			}
		}
	}
}
