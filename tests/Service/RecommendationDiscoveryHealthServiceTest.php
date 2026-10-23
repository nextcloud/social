<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\RecommendationDiscoveryHealthRequest;
use OCA\Social\Service\Discovery\PostSourceStats;
use OCA\Social\Service\RecommendationDiscoveryHealthService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class RecommendationDiscoveryHealthServiceTest extends TestCase {
	public function testRecordsOnlyRealRequestsAndBoundsTheRetentionWindow(): void {
		$request = $this->createMock(RecommendationDiscoveryHealthRequest::class);
		$request->expects($this->once())->method('record')->with('activitypub', new PostSourceStats(2, 1, 1, 4, 3, 180), 2_000_000_000);
		$request->expects($this->once())->method('summary')->with(2_000_000_000 - 168 * 3600)->willReturn([
			'activitypub' => self::source(1, 2, 0, 0),
			'atproto' => self::source(1, 2, 1, 1),
		]);
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn(2_000_000_000);
		$service = new RecommendationDiscoveryHealthService($request, $clock);

		$service->record('activitypub', PostSourceStats::empty());
		$service->record('activitypub', new PostSourceStats(2, 1, 1, 4, 3, 180));
		$summary = $service->summary(10000);

		$this->assertSame(168, $summary['window_hours']);
		$this->assertSame('healthy', $summary['sources']['activitypub']['state']);
		$this->assertSame('degraded', $summary['sources']['atproto']['state']);
		$this->assertSame(0.5, $summary['sources']['atproto']['failure_rate']);
	}

	/** @return array{calls: int, requests: int, failed_requests: int, rate_limited: int, posts_seen: int, posts_stored: int, average_duration_ms: ?int, last_attempt: int, last_success: int} */
	private static function source(int $calls, int $requests, int $failed, int $rateLimited): array {
		return [
			'calls' => $calls,
			'requests' => $requests,
			'failed_requests' => $failed,
			'rate_limited' => $rateLimited,
			'posts_seen' => 4,
			'posts_stored' => 2,
			'average_duration_ms' => 120,
			'last_attempt' => 2_000_000_000,
			'last_success' => 2_000_000_000,
		];
	}
}
