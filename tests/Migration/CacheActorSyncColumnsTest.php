<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Closure;
use OCA\Social\Migration\Version1000Date20260914000003;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The step behind the actor-cache refresh and the queue drain: two columns on
 * `social_cache_actor` and the index `social_req_queue` was sorting without.
 */
class CacheActorSyncColumnsTest extends TestCase {
	use RecordsSchemaChanges;

	/** @var array<string, array<string, array{string, array}>> table => column => [type, options] */
	private array $added = [];
	/** @var array<string, list<array{list<string>, ?string, bool}>> */
	private array $indexes = [];

	/**
	 * @param array<string, list<string>> $existingColumns
	 * @param array<string, list<string>> $existingIndexes
	 */
	private function schemaClosure(
		array $tables = ['social_cache_actor', 'social_req_queue'],
		array $existingColumns = [],
		array $existingIndexes = [],
	): Closure {
		$recorded = [];
		foreach ($tables as $name) {
			$recorded[$name] = $this->recordTable(
				$name, $existingColumns[$name] ?? [], $existingIndexes[$name] ?? []
			);
		}

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			static fn (string $name): bool => isset($recorded[$name])
		);
		$schema->method('getTable')->willReturnCallback(
			static fn (string $name) => $recorded[$name]
		);

		return static fn (): ISchemaWrapper => $schema;
	}

	/**
	 * @param array<string, list<string>> $existingColumns
	 * @param array<string, list<string>> $existingIndexes
	 */
	private function applyStep(
		array $tables = ['social_cache_actor', 'social_req_queue'],
		array $existingColumns = [],
		array $existingIndexes = [],
	): ?ISchemaWrapper {
		$schema = (new Version1000Date20260914000003())->changeSchema(
			$this->createMock(IOutput::class),
			$this->schemaClosure($tables, $existingColumns, $existingIndexes),
			[]
		);
		$this->harvestSchemaChangesByTable();

		return $schema;
	}

	public function testTheRefreshGetsAnAttemptStampAndAFailureCount(): void {
		$this->applyStep();

		$this->assertSame(['sync_attempt', 'sync_failures'], array_keys($this->added['social_cache_actor']));
	}

	/**
	 * A datetime would have to be nullable to mean "never tried", and where a
	 * NULL sorts differs between MySQL and PostgreSQL — which matters here,
	 * because the refresh orders on exactly this column to take its batch.
	 */
	public function testNeverTriedIsZeroRatherThanNull(): void {
		$this->applyStep();

		[$type, $options] = $this->added['social_cache_actor']['sync_attempt'];

		$this->assertSame(Types::BIGINT, $type);
		$this->assertTrue($options['notnull']);
		$this->assertSame(0, $options['default']);
	}

	public function testAnActorThatHasNeverFailedCountsZeroFailures(): void {
		$this->applyStep();

		[$type, $options] = $this->added['social_cache_actor']['sync_failures'];

		$this->assertSame(Types::INTEGER, $type);
		$this->assertTrue($options['notnull']);
		$this->assertSame(0, $options['default']);
	}

	public function testTheAttemptIsIndexedWithTheOtherPredicateOfItsQuery(): void {
		$this->applyStep();

		$this->assertSame(
			[[['local', 'sync_attempt'], 'social_ca_lsa', false]],
			$this->indexes['social_cache_actor']
		);
	}

	/**
	 * `getStandby()` selects on `status` and orders on the other three, 200
	 * rows at a time, against `social_rq_si (status, id)` alone — so every
	 * drain sorted the whole standby set to pick its window.
	 */
	public function testTheQueueDrainGetsTheIndexItsSortNeeds(): void {
		$this->applyStep();

		$this->assertSame(
			[[['status', 'priority', 'tries', 'last'], 'social_rq_sptl', false]],
			$this->indexes['social_req_queue']
		);
	}

	public function testASecondRunAsksForNothing(): void {
		$schema = $this->applyStep(
			['social_cache_actor', 'social_req_queue'],
			['social_cache_actor' => ['sync_attempt', 'sync_failures']],
			['social_cache_actor' => ['social_ca_lsa'], 'social_req_queue' => ['social_rq_sptl']]
		);

		$this->assertSame([], $this->added['social_cache_actor']);
		$this->assertSame([], $this->indexes['social_cache_actor']);
		$this->assertSame([], $this->indexes['social_req_queue']);
		$this->assertNull($schema);
	}

	public function testAHalfAppliedRunStillGetsItsIndex(): void {
		$this->applyStep(
			['social_cache_actor', 'social_req_queue'],
			['social_cache_actor' => ['sync_attempt', 'sync_failures']]
		);

		$this->assertSame([], $this->added['social_cache_actor']);
		$this->assertSame(
			[[['local', 'sync_attempt'], 'social_ca_lsa', false]],
			$this->indexes['social_cache_actor']
		);
	}

	public function testNothingIsAskedOfATableThatIsNotThere(): void {
		$schema = $this->applyStep([]);

		$this->assertSame([], $this->added);
		$this->assertNull($schema);
	}
}
