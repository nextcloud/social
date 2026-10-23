<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\RecommendationExplanationService;
use OCA\Social\Service\RecommendationTopicCatalog;
use PHPUnit\Framework\TestCase;

class RecommendationExplanationServiceTest extends TestCase {
	public function testExplainsAutomaticTopicFollowAndProfileSignalsWithoutRawScores(): void {
		$service = new RecommendationExplanationService(new RecommendationTopicCatalog());
		$reason = $service->explain([
			'matching_topic_ids' => ['tech.apple'],
			'is_followed' => 1.0,
			'behavioral_topic' => 0.6,
			'feedback_alignment' => 1.0,
			'protocol' => 'atproto',
			'_promoted_by_diversity' => true,
		], 7, 2_000_000_000);

		$this->assertSame('AUTOMATIC_PROFILE', $reason['primary']);
		$this->assertSame(['tech.apple'], $reason['topic_ids']);
		$this->assertSame(['FOLLOWED_AUTHOR', 'BEHAVIORAL_PROFILE', 'SIMILARITY_FEEDBACK', 'AUTOMATIC_PROFILE', 'CACHED_CANDIDATE'], $reason['also']);
		$this->assertSame('atproto', $reason['protocol']);
		$this->assertSame('DIVERSITY_PLACEMENT', $reason['rerank_note']);
		$this->assertSame(7, $reason['profile_version']);
		$this->assertArrayNotHasKey('rank_score', $reason);
		$this->assertArrayNotHasKey('behavioral_topic', $reason);
	}

	public function testSensitiveAndUnknownTopicIdsNeverAppearInTheExplanation(): void {
		$service = new RecommendationExplanationService(new RecommendationTopicCatalog());
		$reason = $service->explain([
			'matching_topic_ids' => ['politics.international', 'not.in.catalog', 'tech.apple'],
			'_exploration_slot' => true,
		], 1, 2_000_000_000);

		$this->assertSame('EXPLORATION', $reason['primary']);
		$this->assertSame(['tech.apple'], $reason['topic_ids']);
		$this->assertSame('EXPLORATION_SLOT', $reason['rerank_note']);
		$this->assertSame(['AUTOMATIC_PROFILE', 'CACHED_CANDIDATE'], $reason['also']);
	}
}
