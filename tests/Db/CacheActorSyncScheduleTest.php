<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CacheActorsRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The backoff the actor-cache refresh waits out after a failure.
 *
 * It is a static function precisely so it can be checked here: the same
 * schedule is unrolled into SQL by `limitToSyncDue()` and applied in PHP by
 * `CacheActorService`, and the two disagreeing would mean a row handed out and
 * then ignored on every pass.
 */
class CacheActorSyncScheduleTest extends TestCase {
	public function testAnActorThatHasNeverFailedWaitsTheCacheLifetime(): void {
		$this->assertSame(CacheActorsRequest::CACHE_TTL * 60, CacheActorsRequest::syncWait(0));
		$this->assertSame(10 * 24 * 3600, CacheActorsRequest::syncWait(0));
	}

	/** @return list<array{int, int}> failures, seconds */
	public static function provideSchedule(): array {
		return [
			[1, 3600],
			[2, 7200],
			[3, 14400],
			[4, 28800],
			[10, 1843200],
		];
	}

	#[DataProvider('provideSchedule')]
	public function testEachFailureDoublesTheWait(int $failures, int $seconds): void {
		$this->assertSame($seconds, CacheActorsRequest::syncWait($failures));
	}

	/**
	 * `2 ** $n` is a float in PHP and a wait is a number of seconds; a float
	 * here would be compared against a bigint column.
	 */
	public function testTheWaitIsAlwaysAnInteger(): void {
		for ($failures = 0; $failures <= CacheActorsRequest::SYNC_MAX_FAILURES + 2; $failures++) {
			$this->assertIsInt(CacheActorsRequest::syncWait($failures));
		}
	}

	public function testTheScheduleIsMonotonicAndStopsGrowingAtTheGiveUpThreshold(): void {
		$previous = 0;
		for ($failures = 1; $failures <= CacheActorsRequest::SYNC_MAX_FAILURES; $failures++) {
			$wait = CacheActorsRequest::syncWait($failures);
			$this->assertGreaterThan($previous, $wait);
			$previous = $wait;
		}

		// past the threshold nothing is due at all, so the value only has to
		// stay finite rather than keep doubling into a number of seconds no
		// clock will reach
		$this->assertSame($previous, CacheActorsRequest::syncWait(CacheActorsRequest::SYNC_MAX_FAILURES + 5));
	}

	/**
	 * Ten failures at this schedule span about six weeks: long enough to
	 * outlast any outage a server comes back from, short enough that a server
	 * that is gone stops costing a request every pass.
	 */
	public function testGivingUpTakesAboutSixWeeks(): void {
		$total = 0;
		for ($failures = 1; $failures <= CacheActorsRequest::SYNC_MAX_FAILURES; $failures++) {
			$total += CacheActorsRequest::syncWait($failures);
		}

		$this->assertGreaterThan(28 * 24 * 3600, $total);
		$this->assertLessThan(56 * 24 * 3600, $total);
	}
}
