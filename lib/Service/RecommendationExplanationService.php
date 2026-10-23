<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

/** Creates a small, non-sensitive explanation for the snapshot's actual selection. */
final class RecommendationExplanationService {
	public const ELIGIBILITY_POLICY_VERSION = 1;
	public const EXPLANATION_VERSION = 1;

	public function __construct(
		private RecommendationTopicCatalog $catalog,
	) {
	}

	/**
	 * @param array<string, mixed> $candidate ranked, re-ranked candidate features
	 * @return array{primary: string, topic_ids: list<string>, also: list<string>, candidate_source: string, protocol: string, personalisation_used: bool, selection_stage: string, rerank_note: string, profile_version: int, eligibility_policy_version: int, explanation_version: int, algorithm_version: int, created_at: int}
	 */
	public function explain(array $candidate, int $profileVersion, int $createdAt): array {
		$topicIds = $candidate['matching_topic_ids'] ?? [];
		$topicIds = is_array($topicIds) ? array_values(array_unique(array_filter(
			$topicIds,
			fn (mixed $id): bool => is_string($id)
				&& preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $id) === 1
				&& ($topic = $this->catalog->find($id)) !== null
				&& !in_array('sensitive-topic', $topic['policyTags'], true)
		))) : [];
		$also = [];
		if (($candidate['is_followed'] ?? 0.0) === 1.0) {
			$also[] = 'FOLLOWED_AUTHOR';
		}
		foreach (['behavioral_topic', 'behavioral_author', 'behavioral_format'] as $signal) {
			if (is_numeric($candidate[$signal] ?? null) && (float)$candidate[$signal] !== 0.0) {
				$also[] = 'BEHAVIORAL_PROFILE';
				break;
			}
		}
		if (is_numeric($candidate['feedback_alignment'] ?? null) && (float)$candidate['feedback_alignment'] !== 0.0) {
			$also[] = 'SIMILARITY_FEEDBACK';
		}
		if ($topicIds !== []
			|| (float)($candidate['automatic_interest'] ?? 0.0) > 0.0
			|| (float)($candidate['behavioral_topic'] ?? 0.0) > 0.0) {
			$also[] = 'AUTOMATIC_PROFILE';
		}
		if (is_numeric($candidate['author_affinity'] ?? null) && (float)$candidate['author_affinity'] > 0.0) {
			$also[] = 'AUTHOR_FEEDBACK';
		}
		if (is_numeric($candidate['format_match'] ?? null) && (float)$candidate['format_match'] > 0.0) {
			$also[] = 'FORMAT_FEEDBACK';
		}
		$also[] = 'CACHED_CANDIDATE';

		$exploration = ($candidate['_exploration_slot'] ?? false) === true;
		$diversity = !$exploration && ($candidate['_promoted_by_diversity'] ?? false) === true;
		$protocol = $candidate['protocol'] ?? RecommendationProtocol::ACTIVITYPUB;
		if (!in_array($protocol, [RecommendationProtocol::ACTIVITYPUB, RecommendationProtocol::ATPROTO], true)) {
			$protocol = RecommendationProtocol::ACTIVITYPUB;
		}

		return [
			'primary' => $exploration ? 'EXPLORATION' : 'AUTOMATIC_PROFILE',
			'topic_ids' => array_slice($topicIds, 0, 8),
			'also' => array_values(array_unique($also)),
			'candidate_source' => 'LOCAL_CACHED_POSTS',
			'protocol' => $protocol,
			'personalisation_used' => true,
			'selection_stage' => 'RERANK',
			'rerank_note' => $exploration ? 'EXPLORATION_SLOT' : ($diversity ? 'DIVERSITY_PLACEMENT' : 'BASE_RANK'),
			'profile_version' => max(0, $profileVersion),
			'eligibility_policy_version' => self::ELIGIBILITY_POLICY_VERSION,
			'explanation_version' => self::EXPLANATION_VERSION,
			'algorithm_version' => RecommendationRanker::ALGORITHM_VERSION,
			'created_at' => max(0, $createdAt),
		];
	}
}
