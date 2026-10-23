<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\RecommendationBehavioralProfile;
use OCA\Social\Service\RecommendationExplanationService;
use OCA\Social\Service\RecommendationFeatureExtractor;
use OCA\Social\Service\RecommendationSignalService;
use OCA\Social\Service\RecommendationTimelineAssembler;
use OCA\Social\Service\RecommendationTopicCatalog;
use PHPUnit\Framework\TestCase;

class RecommendationTimelineAssemblerTest extends TestCase {
	public function testDiversityUsesSpecificTopicsInsteadOfTheirBroadParents(): void {
		$catalog = new RecommendationTopicCatalog();
		$assembler = new RecommendationTimelineAssembler(
			$catalog,
			new RecommendationFeatureExtractor($catalog),
			new RecommendationExplanationService($catalog),
			new RecommendationBehavioralProfile($this->createStub(RecommendationSignalService::class), $catalog),
		);
		$post = (new Note())->setContent('<p>PHP backend development is changing.</p>');

		$candidate = $assembler->candidate($post, '1', [], '', 1, [], [
			'topics' => [], 'authors' => [], 'formats' => [],
		], [], [], []);

		self::assertNotNull($candidate);
		self::assertContains('software', $candidate['topic_ids']);
		self::assertContains('software.backend', $candidate['topic_ids']);
		self::assertSame(['software.backend'], $candidate['diversity_topics']);
	}
}
