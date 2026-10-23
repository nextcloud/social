<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

/**
 * Transparent v1 linear ranker. Weights are an initial, uncalibrated
 * hypothesis; missing evidence is neutral and never fabricated.
 */
final class RecommendationRanker {
	public const ALGORITHM_VERSION = 1;
	public const FEATURE_VERSION = 1;

	/** @var array<string, float> */
	private const WEIGHTS = [
		'topic_match' => 0.28,
		'author_affinity' => 0.18,
		'is_followed' => 0.12,
		'freshness' => 0.12,
		'format_match' => 0.10,
		'usefulness' => 0.08,
		'feedback_alignment' => 0.07,
		'novelty' => 0.05,
	];

	/**
	 * @param list<array<string, mixed>> $candidates
	 * @return list<array<string, mixed>>
	 */
	public function rank(array $candidates): array {
		$ranked = [];
		foreach ($candidates as $candidate) {
			if (!isset($candidate['nid']) || !is_scalar($candidate['nid']) || (isset($candidate['eligible']) && $candidate['eligible'] !== true)) {
				continue;
			}

			$features = [
				'topic_match' => self::signed($candidate['topic_match'] ?? null, 0.0),
				'author_affinity' => self::unit($candidate['author_affinity'] ?? null, 0.5),
				'is_followed' => self::unit($candidate['is_followed'] ?? null, 0.5),
				'freshness' => self::unit($candidate['freshness'] ?? null, 0.5),
				'format_match' => self::signed($candidate['format_match'] ?? null, 0.0),
				'usefulness' => self::unit($candidate['usefulness'] ?? null, 0.5),
				'feedback_alignment' => self::signed($candidate['feedback_alignment'] ?? null, 0.0),
				'novelty' => self::unit($candidate['novelty'] ?? null, 0.0),
				'exposure_penalty' => self::unit($candidate['exposure_penalty'] ?? null, 0.0),
			];

			$score = 0.0;
			foreach (self::WEIGHTS as $key => $weight) {
				$feature = $features[$key];
				$score += $weight * (in_array($key, ['topic_match', 'format_match', 'feedback_alignment'], true)
					? ($feature + 1.0) / 2.0
					: $feature);
			}
			$score -= 0.12 * $features['exposure_penalty'];

			$candidate['rank_score'] = $score;
			$candidate['rank_features'] = $features;
			$candidate['algorithm_version'] = self::ALGORITHM_VERSION;
			$candidate['feature_version'] = self::FEATURE_VERSION;
			$ranked[] = $candidate;
		}

		usort($ranked, static function (array $left, array $right): int {
			$scoreOrder = $right['rank_score'] <=> $left['rank_score'];
			if ($scoreOrder !== 0) {
				return $scoreOrder;
			}

			$reliabilityOrder = self::unit($right['source_reliability'] ?? null, 0.5)
				<=> self::unit($left['source_reliability'] ?? null, 0.5);
			if ($reliabilityOrder !== 0) {
				return $reliabilityOrder;
			}

			return (string)$left['nid'] <=> (string)$right['nid'];
		});

		return $ranked;
	}

	private static function unit(mixed $value, float $default): float {
		if (!is_numeric($value) || !is_finite((float)$value)) {
			return $default;
		}

		return max(0.0, min(1.0, (float)$value));
	}

	private static function signed(mixed $value, float $default): float {
		if (!is_numeric($value) || !is_finite((float)$value)) {
			return $default;
		}

		return max(-1.0, min(1.0, (float)$value));
	}
}
