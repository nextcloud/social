<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Db\ScheduledStatusesRequestBuilder;
use OCP\DB\Types;
use PHPUnit\Framework\TestCase;

/** The table a post waits in. */
class ScheduledStatusesTableTest extends TestCase {
	use ReadsTheSchema;

	private const TABLE = 'social_scheduled';

	public function testTheTableIsTheOneTheCodeReadsAndWrites(): void {
		$this->assertSame(self::TABLE, ScheduledStatusesRequestBuilder::TABLE_SCHEDULED);
		$this->assertNotEmpty($this->columnNames(self::TABLE));
	}

	public function testTheOwnerIsStoredTheShapeTheRestOfTheSchemaScopesAccountsBy(): void {
		[$type, $options] = $this->column(self::TABLE, 'actor_id_prim');
		$this->assertSame(Types::STRING, $type);
		$this->assertSame(32, $options['length'], 'a prim is an md5');
		$this->assertTrue($options['notnull']);

		// a prim is one-way, and the cron has no session to resolve a poster
		// from: it starts from the URI in this column
		[$type, $options] = $this->column(self::TABLE, 'actor_id');
		$this->assertSame(Types::TEXT, $type);
		$this->assertTrue($options['notnull']);
	}

	/**
	 * A row with no time is one the job would never pick up and the owner could
	 * never publish: a post lost with no way to tell.
	 */
	public function testTheTimeIsADateAndIsRequired(): void {
		[$type, $options] = $this->column(self::TABLE, 'scheduled_at');

		$this->assertSame(Types::DATETIME, $type);
		$this->assertTrue($options['notnull']);
	}

	/**
	 * `params` is the client's own request, answered back verbatim as
	 * `ScheduledStatus.params`, and is the whole of what the job replays. A
	 * 5000-character post plus its poll does not fit anything narrower.
	 */
	public function testTheRequestIsKeptWholeAndUnbounded(): void {
		$this->assertSame(Types::TEXT, $this->column(self::TABLE, 'params')[0]);
	}

	public function testTheKeyIsTheAutoincrementTheApiHandsTheClient(): void {
		// it is the id in the entity, the path segment of the three single-row
		// routes and the cursor the index route pages on, and it cannot be
		// derived: two identical posts may be scheduled for the same minute
		$this->assertSame(['id'], $this->primaryKeyOf(self::TABLE));

		[$type, $options] = $this->column(self::TABLE, 'id');
		$this->assertSame(Types::BIGINT, $type);
		$this->assertTrue($options['autoincrement']);
	}

	public function testOneAccountsWaitingPostsAreFoundInScheduledOrder(): void {
		// GET /api/v1/scheduled_statuses is one account's rows soonest first,
		// and the daily cap counts one account's rows inside one day
		$this->assertContains(
			[['actor_id_prim', 'scheduled_at'], 'social_sched_as', false],
			$this->indexesOf(self::TABLE)
		);
	}

	/**
	 * The job asks the opposite question — which rows across *all* accounts are
	 * due — and the per-account index cannot answer it: its leading column is
	 * the account. Without this one every cron run is a full scan.
	 */
	public function testTheDueRowsAreFoundWithoutScanningTheTable(): void {
		$this->assertContains(
			[['scheduled_at'], 'social_sched_due', false],
			$this->indexesOf(self::TABLE)
		);
	}
}
