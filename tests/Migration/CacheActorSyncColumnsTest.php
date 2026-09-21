<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCP\DB\Types;
use PHPUnit\Framework\TestCase;

/**
 * What the actor-cache refresh and the queue drain need of the schema: two
 * columns on `social_cache_actor` and the index `social_req_queue` was sorting
 * without.
 */
class CacheActorSyncColumnsTest extends TestCase {
	use ReadsTheSchema;

	public function testTheRefreshHasAnAttemptStampAndAFailureCount(): void {
		$columns = $this->columnNames('social_cache_actor');

		$this->assertContains('sync_attempt', $columns);
		$this->assertContains('sync_failures', $columns);
	}

	/**
	 * A datetime would have to be nullable to mean "never tried", and where a
	 * NULL sorts differs between MySQL and PostgreSQL — which matters here,
	 * because the refresh orders on exactly this column to take its batch.
	 */
	public function testNeverTriedIsZeroRatherThanNull(): void {
		[$type, $options] = $this->column('social_cache_actor', 'sync_attempt');

		$this->assertSame(Types::BIGINT, $type);
		$this->assertTrue($options['notnull']);
		$this->assertSame(0, $options['default']);
	}

	public function testAnActorThatHasNeverFailedCountsZeroFailures(): void {
		[$type, $options] = $this->column('social_cache_actor', 'sync_failures');

		$this->assertSame(Types::INTEGER, $type);
		$this->assertTrue($options['notnull']);
		$this->assertSame(0, $options['default']);
	}

	public function testTheAttemptIsIndexedWithTheOtherPredicateOfItsQuery(): void {
		$this->assertContains(
			[['local', 'sync_attempt'], 'social_ca_lsa', false],
			$this->indexesOf('social_cache_actor')
		);
	}

	/**
	 * `getStandby()` selects on `status` and orders on the other three, 200
	 * rows at a time, against `social_rq_si (status, id)` alone — so every
	 * drain sorted the whole standby set to pick its window.
	 */
	public function testTheQueueDrainHasTheIndexItsSortNeeds(): void {
		$this->assertContains(
			[['status', 'priority', 'tries', 'last'], 'social_rq_sptl', false],
			$this->indexesOf('social_req_queue')
		);
	}
}
