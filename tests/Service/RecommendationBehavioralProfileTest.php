<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\RecommendationBehavioralProfile;
use PHPUnit\Framework\TestCase;

class RecommendationBehavioralProfileTest extends TestCase {
	public function testNoveltyRewardsUnfamiliarAuthorsButDiminishesForFamiliarOrRepeatedContent(): void {
		$this->assertSame(1.0, RecommendationBehavioralProfile::novelty(0.0, false, 0.0));
		$this->assertSame(0.5, RecommendationBehavioralProfile::novelty(0.0, true, 0.0));
		$this->assertEqualsWithDelta(0.1, RecommendationBehavioralProfile::novelty(0.8, false, 0.5), 0.00001);
		$this->assertEqualsWithDelta(0.1, RecommendationBehavioralProfile::novelty(-0.8, false, 0.5), 0.00001);
		$this->assertSame(0.0, RecommendationBehavioralProfile::novelty(1.0, false, 1.0));
	}
}
