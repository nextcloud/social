<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Closure;
use OCA\Social\Migration\Version1000Date20230217000001;
use OCA\Social\Migration\Version1000Date20230217000002;
use OCA\Social\Migration\Version1000Date20230407000001;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The three 2023 steps, which exist only to repair the 2022 one.
 *
 * Two of them chase the same defect: `social_cache_actor` and
 * `social_cache_doc` were created keyed by the hashed ActivityPub id, and the
 * fix is to drop that key and put an autoincrement `nid` in its place. It takes
 * two steps because the drop and the replacement cannot be in the same schema
 * change on every supported platform.
 *
 * They were untested until now, which is half the reason the 2022–2023 block
 * cannot be squashed: what a squash would have to preserve is the end state of
 * these four steps together, and nothing said what that was.
 */
class SchemaRepairs2023Test extends TestCase {
	use RecordsSchemaChanges;

	/** @var array<string, FakeTable> */
	private array $tables = [];

	/**
	 * @param array<string, list<string>> $columns table => the columns it has
	 */
	private function schemaClosure(array $columns): Closure {
		$this->tables = [];
		foreach ($columns as $name => $existing) {
			$this->tables[$name] = $this->recordTable($name, $existing);
		}

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')
			->willReturnCallback(fn (string $name): bool => isset($this->tables[$name]));
		$schema->method('getTable')
			->willReturnCallback(function (string $name) {
				return $this->tables[$name];
			});

		return fn (): ISchemaWrapper => $schema;
	}

	/** @param array<string, list<string>> $columns */
	private function apply(object $step, array $columns): void {
		$step->changeSchema($this->createMock(IOutput::class), $this->schemaClosure($columns), []);
	}

	public function testTheFirstStepOnlyDropsTheKeyItIsAboutToReplace(): void {
		$this->apply(new Version1000Date20230217000001(), [
			'social_cache_actor' => ['id_prim'],
			'social_cache_doc' => ['id_prim'],
		]);

		foreach (['social_cache_actor', 'social_cache_doc'] as $table) {
			$this->assertTrue($this->tables[$table]->primaryKeyWasDropped(), $table);
			$this->assertSame([], $this->tables[$table]->addedColumns(), $table);
		}
	}

	public function testTheFirstStepLeavesATableThatAlreadyHasTheNewKeyAlone(): void {
		// the guard is `nid` being there, which is what the second step adds
		$this->apply(new Version1000Date20230217000001(), [
			'social_cache_actor' => ['id_prim', 'nid'],
			'social_cache_doc' => ['id_prim', 'nid'],
		]);

		foreach (['social_cache_actor', 'social_cache_doc'] as $table) {
			$this->assertFalse($this->tables[$table]->primaryKeyWasDropped(), $table);
		}
	}

	public function testTheSecondStepPutsAnAutoincrementKeyOnBothCacheTables(): void {
		$this->apply(new Version1000Date20230217000002(), [
			'social_stream' => [],
			'social_cache_actor' => [],
			'social_cache_doc' => [],
		]);

		foreach (['social_cache_actor', 'social_cache_doc'] as $table) {
			[$type, $options] = $this->tables[$table]->addedColumn('nid');
			$this->assertSame(Types::BIGINT, $type, $table);
			$this->assertTrue($options['autoincrement'], $table);
			$this->assertSame(['nid'], $this->tables[$table]->addedPrimaryKey(), $table);
		}
	}

	public function testTheSecondStepAddsTheColumnsWithDefaultsATableWithRowsCanTake(): void {
		$this->apply(new Version1000Date20230217000002(), [
			'social_stream' => [],
			'social_cache_actor' => [],
			'social_cache_doc' => [],
		]);

		// PostgreSQL refuses a NOT NULL column on a populated table unless it
		// has a default; MySQL quietly invents one. Every NOT NULL column
		// these repairs add therefore carries an explicit default.
		foreach (['account', 'meta', 'blurhash', 'description'] as $column) {
			[, $options] = $this->tables['social_cache_doc']->addedColumn($column);
			$this->assertTrue($options['notnull'], $column);
			$this->assertArrayHasKey('default', $options, $column);
		}

		[$type, $options] = $this->tables['social_stream']->addedColumn('visibility');
		$this->assertSame(Types::STRING, $type);
		$this->assertFalse($options['notnull']);
	}

	public function testTheThirdStepAddsTheDetailsTimestampAsNullable(): void {
		$this->apply(new Version1000Date20230407000001(), ['social_cache_actor' => []]);

		[$type, $options] = $this->tables['social_cache_actor']->addedColumn('details_update');
		// nullable because an actor cached before this step has no moment to
		// record, and "never refreshed" is what null means here
		$this->assertSame(Types::DATETIME, $type);
		$this->assertFalse($options['notnull']);
	}

	public function testEachStepAsksForNothingOnASecondRun(): void {
		$repaired = [
			'social_stream' => ['visibility'],
			'social_cache_actor' => ['nid', 'details_update'],
			'social_cache_doc' => ['nid', 'account', 'meta', 'blurhash', 'description'],
		];

		foreach ([
			new Version1000Date20230217000001(),
			new Version1000Date20230217000002(),
			new Version1000Date20230407000001(),
		] as $step) {
			$this->apply($step, $repaired);

			foreach ($this->tables as $name => $table) {
				$this->assertSame([], $table->addedColumns(), $name . ' after ' . $step::class);
				$this->assertFalse($table->primaryKeyWasDropped(), $name . ' after ' . $step::class);
			}
		}
	}

	public function testNoStepTouchesATableThatIsNotThere(): void {
		foreach ([
			new Version1000Date20230217000001(),
			new Version1000Date20230217000002(),
			new Version1000Date20230407000001(),
		] as $step) {
			$this->apply($step, []);

			$this->assertSame([], $this->tables);
		}
	}
}
