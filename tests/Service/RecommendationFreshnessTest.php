<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\RecommendationFreshness;
use PHPUnit\Framework\TestCase;

class RecommendationFreshnessTest extends TestCase {
	public function testClassifiesOnlyExplicitTimeAndContentCues(): void {
		self::assertSame('breaking', RecommendationFreshness::contentClass('<p>Eilmeldung: Live-Ticker zur Wahl</p>'));
		self::assertSame('breaking', RecommendationFreshness::contentClass('<p>Breaking: live updates from the event</p>'));
		self::assertSame('security', RecommendationFreshness::contentClass('<p>Security advisory for CVE-2026-12345</p>'));
		self::assertSame('security', RecommendationFreshness::contentClass('<p>Wichtige Sicherheitslücke in der Bibliothek</p>'));
		self::assertSame('evergreen', RecommendationFreshness::contentClass('<p>Anleitung zur Nextcloud-Migration und Fehlerbehebung</p>'));
		self::assertSame('evergreen', RecommendationFreshness::contentClass('<p>A practical tutorial and documentation guide</p>'));
		self::assertSame('announcement', RecommendationFreshness::contentClass('<p>We announce a new software release</p>'));
		self::assertSame('default', RecommendationFreshness::contentClass('<p>Apple fans share photos from the weekend</p>'));
	}

	public function testEvergreenGuidanceDecaysGentlyWhileBreakingNewsDecaysQuickly(): void {
		$evergreen = RecommendationFreshness::scoreAgeHours(24.0 * 15, 'evergreen');
		$breaking = RecommendationFreshness::scoreAgeHours(24.0, 'breaking');
		$default = RecommendationFreshness::scoreAgeHours(24.0 * 15, 'default');

		self::assertGreaterThan(0.85, $evergreen, 'A 15-day-old useful guide remains a strong candidate.');
		self::assertLessThan(0.1, $breaking, 'Live-event updates lose priority quickly.');
		self::assertLessThan(0.02, $default, 'Unclassified posts retain the existing short-lived freshness preference.');
	}

	public function testMediaFreshnessAndInvalidDatesRemainBounded(): void {
		$short = RecommendationFreshness::scoreAgeHours(24.0, 'default', 'short');
		$text = RecommendationFreshness::scoreAgeHours(24.0, 'default', 'text');
		$unknown = RecommendationFreshness::scoreAgeHours(24.0, 'unknown', 'text');

		self::assertLessThan($text, $short);
		self::assertSame($text, $unknown);
		self::assertSame(0.5, RecommendationFreshness::score(new Note()->setPublishedTime(0), 2_000_000_000));
		self::assertSame(1.0, RecommendationFreshness::scoreAgeHours(-12.0));
		self::assertSame(0.5, RecommendationFreshness::scoreAgeHours(INF));
	}

	public function testCandidateBudgetReservesAStableHistoricalShare(): void {
		self::assertSame(80, RecommendationFreshness::recentCandidateLimit(100));
		self::assertSame(20, RecommendationFreshness::historicalCandidateLimit(100));
		self::assertSame(4, RecommendationFreshness::recentCandidateLimit(5));
		self::assertSame(1, RecommendationFreshness::historicalCandidateLimit(5));
		self::assertSame(1, RecommendationFreshness::recentCandidateLimit(1));
		self::assertSame(0, RecommendationFreshness::historicalCandidateLimit(1));
	}

	public function testScoreUsesTheSharedContentClassPolicyForActivityPubAndAtprotoStreams(): void {
		$now = 2_000_000_000;
		$published = $now - (15 * 86400);
		$activityPub = (new Note())->setContent('<p>Nextcloud migration guide</p>')->setPublishedTime($published);
		$atproto = (new Note())->setContent('<p>Nextcloud migration guide</p>')->setPublishedTime($published);

		self::assertSame(
			RecommendationFreshness::score($activityPub, $now),
			RecommendationFreshness::score($atproto, $now)
		);
		self::assertGreaterThan(0.85, RecommendationFreshness::score($activityPub, $now));
	}
}
