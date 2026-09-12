<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Closure;
use OCA\Social\Db\ScheduledStatusesRequestBuilder;
use OCA\Social\Migration\Version1000Date20260911000014;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The table a post waits in.
 *
 * What the DDL becomes on each platform is verified against the real DBAL
 * outside this suite (see the class docblock of the step); what is checked here
 * is the schema the step asks for, that the indexes are the two reads that
 * actually happen, and that a second run asks for nothing.
 */
class ScheduledStatusesTableTest extends TestCase {
	use RecordsSchemaChanges;

	private const TABLE = 'social_scheduled';

	/** @var array<string, array{string, array}> column => [type, options] */
	private array $added = [];
	/** @var array<int, array{string[], string, bool}> [columns, name, unique] */
	private array $indexes = [];
	/** @var string[] */
	private array $primaryKey = [];
	/** @var string[] the tables the step created */
	private array $created = [];

	/** @param string[] $existing tables that are already there */
	private function schemaClosure(array $existing = []): Closure {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')
			->willReturnCallback(static fn (string $name): bool => in_array($name, $existing, true));
		$schema->method('createTable')
			->willReturnCallback(function (string $name) {
				$this->created[] = $name;

				return $this->recordTable($name);
			});

		return static fn (): ISchemaWrapper => $schema;
	}

	/** @param string[] $existing */
	private function migrate(array $existing = []): ?ISchemaWrapper {
		$step = new Version1000Date20260911000014();

		$schema = $step->changeSchema($this->createMock(IOutput::class), $this->schemaClosure($existing), []);
		$this->harvestSchemaChanges();

		return $schema;
	}

	public function testTheTableIsTheOneTheCodeReadsAndWrites(): void {
		$this->migrate();

		$this->assertSame([self::TABLE], $this->created);
		$this->assertSame(self::TABLE, ScheduledStatusesRequestBuilder::TABLE_SCHEDULED);
	}

	public function testTheOwnerIsStoredTheShapeTheRestOfTheSchemaScopesAccountsBy(): void {
		$this->migrate();

		[$type, $options] = $this->added['actor_id_prim'];
		$this->assertSame(Types::STRING, $type);
		$this->assertSame(32, $options['length'], 'a prim is an md5');
		$this->assertTrue($options['notnull']);

		// a prim is one-way, and the cron has no session to resolve a poster
		// from: it starts from the URI in this column
		[$type, $options] = $this->added['actor_id'];
		$this->assertSame(Types::TEXT, $type);
		$this->assertTrue($options['notnull']);
	}

	/**
	 * A row with no time is one the job would never pick up and the owner could
	 * never publish: a post lost with no way to tell.
	 */
	public function testTheTimeIsADateAndIsRequired(): void {
		$this->migrate();

		[$type, $options] = $this->added['scheduled_at'];
		$this->assertSame(Types::DATETIME, $type);
		$this->assertTrue($options['notnull']);
	}

	/**
	 * `params` is the client's own request, answered back verbatim as
	 * `ScheduledStatus.params`, and is the whole of what the job replays. A
	 * 5000-character post plus its poll does not fit anything narrower.
	 */
	public function testTheRequestIsKeptWholeAndUnbounded(): void {
		$this->migrate();

		$this->assertSame(Types::TEXT, $this->added['params'][0]);
	}

	public function testTheKeyIsTheAutoincrementTheApiHandsTheClient(): void {
		// it is the id in the entity, the path segment of the three single-row
		// routes and the cursor the index route pages on, and it cannot be
		// derived: two identical posts may be scheduled for the same minute
		$this->migrate();

		$this->assertSame(['id'], $this->primaryKey);
		[$type, $options] = $this->added['id'];
		$this->assertSame(Types::BIGINT, $type);
		$this->assertTrue($options['autoincrement']);
	}

	public function testOneAccountsWaitingPostsAreFoundInScheduledOrder(): void {
		// GET /api/v1/scheduled_statuses is one account's rows soonest first,
		// and the daily cap counts one account's rows inside one day
		$this->migrate();

		$byActor = array_values(array_filter(
			$this->indexes,
			static fn (array $i): bool => $i[0] === ['actor_id_prim', 'scheduled_at']
		));

		$this->assertCount(1, $byActor);
		$this->assertSame('social_sched_as', $byActor[0][1]);
	}

	/**
	 * The job asks the opposite question — which rows across *all* accounts are
	 * due — and the per-account index cannot answer it: its leading column is
	 * the account. Without this one every cron run is a full scan.
	 */
	public function testTheDueRowsAreFoundWithoutScanningTheTable(): void {
		$this->migrate();

		$byTime = array_values(array_filter(
			$this->indexes,
			static fn (array $i): bool => $i[0] === ['scheduled_at']
		));

		$this->assertCount(1, $byTime);
		$this->assertSame('social_sched_due', $byTime[0][1]);
	}

	public function testASecondRunAsksForNothing(): void {
		$schema = $this->migrate([self::TABLE]);

		$this->assertNull($schema);
		$this->assertSame([], $this->created);
	}
}
