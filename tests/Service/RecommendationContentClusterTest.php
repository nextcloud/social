<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\RecommendationContentCluster;
use OCA\Social\Service\RecommendationTopicCatalog;
use PHPUnit\Framework\TestCase;

class RecommendationContentClusterTest extends TestCase {
	private RecommendationTopicCatalog $catalog;

	protected function setUp(): void {
		$this->catalog = new RecommendationTopicCatalog();
	}

	public function testTopicOrderDoesNotChangeTheContentClusterKey(): void {
		$this->assertSame(
			RecommendationContentCluster::key(['technology', 'tech.apple'], 'text', $this->catalog),
			RecommendationContentCluster::key(['tech.apple', 'technology'], 'text', $this->catalog)
		);
	}

	public function testFormatAndTopicCombinationRemainDistinct(): void {
		$topics = ['technology', 'tech.apple'];
		$text = RecommendationContentCluster::key($topics, 'text', $this->catalog);

		$this->assertNotSame($text, RecommendationContentCluster::key($topics, 'image', $this->catalog));
		$this->assertNotSame($text, RecommendationContentCluster::key(['technology'], 'text', $this->catalog));
	}

	public function testSensitiveTopicsAreExcludedFromClusterIdentity(): void {
		$withPolitics = RecommendationContentCluster::key(['politics.international', 'tech.apple'], 'text', $this->catalog);
		$withoutPolitics = RecommendationContentCluster::key(['tech.apple'], 'text', $this->catalog);

		$this->assertSame($withoutPolitics, $withPolitics);
		$this->assertNull(RecommendationContentCluster::key(['politics.international'], 'text', $this->catalog));
	}
}
