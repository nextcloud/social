<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\RecommendationStoryFingerprint;
use PHPUnit\Framework\TestCase;

class RecommendationStoryFingerprintTest extends TestCase {
	public function testSimilarStoriesShareSeveralOwnerScopedBandsWithoutStoringTerms(): void {
		$secret = 'an instance secret used only for test signatures';
		$first = ['php', 'security', 'incident', 'patch', 'release', 'maintainer', 'vulnerability', 'disclosure'];
		$variant = ['vulnerability', 'patch', 'php', 'security', 'incident', 'release', 'maintainer', 'hotfix'];

		$firstKeys = RecommendationStoryFingerprint::keys($first, $secret, 'alice');
		$variantKeys = RecommendationStoryFingerprint::keys($variant, $secret, 'alice');

		$this->assertCount(16, $firstKeys);
		$this->assertGreaterThanOrEqual(2, count(array_intersect($firstKeys, $variantKeys)));
		$unrelated = RecommendationStoryFingerprint::keys(
			['gardening', 'seedlings', 'tomatoes', 'greenhouse', 'watering', 'compost', 'harvest', 'soil'],
			$secret,
			'alice',
		);
		$this->assertLessThan(2, count(array_intersect($firstKeys, $unrelated)), 'unrelated stories must not meet the fuzzy-match threshold');
		$this->assertSame($firstKeys, RecommendationStoryFingerprint::keys(array_reverse($first), $secret, 'alice'));
		$this->assertNotSame($firstKeys, RecommendationStoryFingerprint::keys($first, $secret, 'bob'));
		$this->assertNotSame($firstKeys, RecommendationStoryFingerprint::keys($first, 'another instance secret', 'alice'));
	}

	public function testShortTextAndMissingSecretDoNotProduceStorySignatures(): void {
		$this->assertSame([], RecommendationStoryFingerprint::keys(['php', 'security'], 'secret', 'alice'));
		$this->assertSame([], RecommendationStoryFingerprint::keys(['php', 'security', 'incident'], '', 'alice'));
		$this->assertSame([], RecommendationStoryFingerprint::keys(['php', 'security', 'incident'], 'secret', ''));
	}

	public function testExactPostKeysAreHmacsScopedToOwnerAndSecret(): void {
		$key = RecommendationStoryFingerprint::exactPostKey('123', 'server-secret', 'alice');

		$this->assertNotNull($key);
		$this->assertSame($key, RecommendationStoryFingerprint::exactPostKey('123', 'server-secret', 'alice'));
		$this->assertNotSame($key, RecommendationStoryFingerprint::exactPostKey('123', 'server-secret', 'bob'));
		$this->assertNotSame($key, RecommendationStoryFingerprint::exactPostKey('123', 'another-secret', 'alice'));
		$this->assertNotSame($key, RecommendationStoryFingerprint::exactPostKey('124', 'server-secret', 'alice'));
		$this->assertNull(RecommendationStoryFingerprint::exactPostKey('123', '', 'alice'));
		$this->assertNull(RecommendationStoryFingerprint::exactPostKey('123', 'server-secret', ''));
		$this->assertNull(RecommendationStoryFingerprint::exactPostKey('', 'server-secret', 'alice'));
	}

	public function testShortStoriesKeepTheirExactKeyAndGetFuzzyBandsForHeadlineVariants(): void {
		$secret = 'an instance secret used only for test signatures';
		$first = RecommendationStoryFingerprint::keys(['php', 'security', 'incident', 'patch'], $secret, 'alice');
		$variant = RecommendationStoryFingerprint::keys(['php', 'security', 'incident', 'hotfix'], $secret, 'alice');

		$this->assertCount(17, $first, 'short stories keep the legacy exact key and add sixteen MinHash samples');
		$this->assertSame(
			hash_hmac('sha256', "alice\0story-exact-v1\0" . 0 . "\0incident\0patch\0php\0security", $secret),
			$first[0],
			'existing exact exposure rows still match after upgrade',
		);
		$this->assertGreaterThanOrEqual(RecommendationStoryFingerprint::SHORT_STORY_MATCH_COUNT, count(array_intersect(array_slice($first, 1), array_slice($variant, 1))));
	}
}
