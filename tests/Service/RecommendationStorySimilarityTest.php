<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\RecommendationStorySimilarity;
use PHPUnit\Framework\TestCase;

class RecommendationStorySimilarityTest extends TestCase {
	public function testSimilarReportsAreMatchedAcrossSmallWordingChanges(): void {
		$first = RecommendationStorySimilarity::terms('PHP security advisory CVE-2026-1234 affects installations and allows remote code execution.');
		$second = RecommendationStorySimilarity::terms('A PHP security warning for CVE 2026 1234 says installations allow remote code execution.');

		$this->assertTrue(RecommendationStorySimilarity::areSimilar($first, $second));
	}

	public function testUnrelatedPostsInTheSameBroadTopicAreNotMatched(): void {
		$security = RecommendationStorySimilarity::terms('PHP security advisory CVE-2026-1234 affects installations and allows remote code execution.');
		$development = RecommendationStorySimilarity::terms('PHP developers published a new framework release with faster database queries and templates.');

		$this->assertFalse(RecommendationStorySimilarity::areSimilar($security, $development));
	}

	public function testQuotedMaterialDoesNotDetermineStorySimilarity(): void {
		$original = RecommendationStorySimilarity::terms('A local gardening group is meeting this weekend.');
		$quoted = RecommendationStorySimilarity::terms('<blockquote>PHP security advisory CVE-2026-1234 affects installations and allows remote code execution.</blockquote> A local gardening group is meeting this weekend.');

		$this->assertTrue(RecommendationStorySimilarity::areSimilar($original, $quoted));
	}
}
