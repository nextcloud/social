<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Closure;
use OCA\Social\Db\FiltersRequestBuilder;
use OCA\Social\Migration\Version1000Date20260911000006;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The two tables a keyword filter lives in.
 *
 * What the DDL becomes on each platform is verified against the real DBAL
 * outside this suite (see the class docblock of the step); what is checked
 * here is the schema the step asks for, that the names are the ones the code
 * reads and writes, and that a second run asks for nothing.
 */
class FiltersTablesTest extends TestCase {
	use RecordsSchemaChanges;

	/** @var array<string, array<string, array{string, array}>> table => column => [type, options] */
	private array $added = [];
	/** @var array<string, array<int, array{string[], string, bool}>> table => [columns, name, unique] */
	private array $indexes = [];
	/** @var array<string, string[]> table => primary key columns */
	private array $primaryKey = [];
	/** @var string[] the tables the step created, in order */
	private array $created = [];

	/** @param string[] $existing tables the schema already has */
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
		$step = new Version1000Date20260911000006();

		$schema = $step->changeSchema($this->createMock(IOutput::class), $this->schemaClosure($existing), []);
		$this->harvestSchemaChangesByTable();

		return $schema;
	}

	public function testTheTablesAreTheOnesTheCodeReadsAndWrites(): void {
		$this->migrate();

		$this->assertSame(
			[FiltersRequestBuilder::TABLE_FILTERS, FiltersRequestBuilder::TABLE_FILTER_KEYWORDS],
			$this->created
		);
	}

	public function testAFilterCarriesEverythingTheApiPromises(): void {
		$this->migrate();

		$this->assertSame(
			['id', 'actor_id_prim', 'title', 'contexts', 'action', 'expires_at', 'creation'],
			array_keys($this->added[FiltersRequestBuilder::TABLE_FILTERS])
		);
	}

	public function testTheOwnerIsTheShapeTheRestOfTheSchemaUses(): void {
		$this->migrate();

		[$type, $options] = $this->added[FiltersRequestBuilder::TABLE_FILTERS]['actor_id_prim'];
		$this->assertSame(Types::STRING, $type);
		$this->assertSame(32, $options['length'], 'a prim is an md5');
		$this->assertTrue($options['notnull']);
	}

	public function testAFilterMayNeverExpire(): void {
		// NULL is "never", and it is the only reading that does not make every
		// filter made before 1970 expired
		$this->migrate();

		[$type, $options] = $this->added[FiltersRequestBuilder::TABLE_FILTERS]['expires_at'];
		$this->assertSame(Types::DATETIME, $type);
		$this->assertFalse($options['notnull']);
	}

	public function testTheIndexIsTheOneEveryReadUses(): void {
		// every read of a filter — the API and every filtered timeline — asks
		// for one account's filters and nothing else
		$this->migrate();

		$this->assertSame(
			[[['actor_id_prim'], 'social_flt_actor', false]],
			$this->indexes[FiltersRequestBuilder::TABLE_FILTERS]
		);
		$this->assertSame(
			[[['filter_id'], 'social_fltkw_filter', false]],
			$this->indexes[FiltersRequestBuilder::TABLE_FILTER_KEYWORDS]
		);
	}

	public function testAKeywordNamesItsFilterAndCarriesItsFlag(): void {
		$this->migrate();

		$columns = $this->added[FiltersRequestBuilder::TABLE_FILTER_KEYWORDS];
		$this->assertSame(['id', 'filter_id', 'keyword', 'whole_word', 'creation'], array_keys($columns));
		$this->assertSame(Types::BIGINT, $columns['filter_id'][0]);
		$this->assertSame(Types::SMALLINT, $columns['whole_word'][0]);
		$this->assertSame(0, $columns['whole_word'][1]['default']);
	}

	public function testBothRowsCarryAnAutoincrementKeyTheApiAddressesThemBy(): void {
		$this->migrate();

		foreach ([FiltersRequestBuilder::TABLE_FILTERS, FiltersRequestBuilder::TABLE_FILTER_KEYWORDS] as $table) {
			$this->assertSame(['id'], $this->primaryKey[$table]);
			[$type, $options] = $this->added[$table]['id'];
			$this->assertSame(Types::BIGINT, $type);
			$this->assertTrue($options['autoincrement']);
		}
	}

	public function testASecondRunCreatesNothing(): void {
		$schema = $this->migrate(
			[FiltersRequestBuilder::TABLE_FILTERS, FiltersRequestBuilder::TABLE_FILTER_KEYWORDS]
		);

		$this->assertNull($schema, 'a step that changes nothing returns null');
		$this->assertSame([], $this->created);
	}

	public function testAStepInterruptedBetweenTheTwoTablesFinishesOnTheNextRun(): void {
		$schema = $this->migrate([FiltersRequestBuilder::TABLE_FILTERS]);

		$this->assertNotNull($schema);
		$this->assertSame([FiltersRequestBuilder::TABLE_FILTER_KEYWORDS], $this->created);
	}
}
