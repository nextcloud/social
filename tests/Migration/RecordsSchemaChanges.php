<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

/**
 * Hands a migration test a {@see FakeTable} and copies what the step asked for
 * into the properties the assertions read.
 *
 * Each of these tests used to carry its own anonymous table class. Nextcloud 35
 * typed `ISchemaWrapper::getTable()` and `createTable()` as returning `ITable`,
 * which those doubles did not implement, so the doubling moved to one class and
 * this trait keeps the recorded shapes the tests were already written against.
 */
trait RecordsSchemaChanges {
	/** @var list<FakeTable> */
	private array $recordedTables = [];

	/**
	 * @param list<string> $existingColumns columns the table already has
	 * @param list<string> $existingIndexes indexes the table already has
	 */
	private function recordTable(string $name, array $existingColumns = [], array $existingIndexes = []): FakeTable {
		$table = new FakeTable($name, $existingColumns, $existingIndexes);
		$this->recordedTables[] = $table;

		return $table;
	}

	/**
	 * Fills `$added`, `$indexes` and `$primaryKey` where the test declares them.
	 * A test that does not assert on indexes does not declare the property, so
	 * each one is filled only if it is there to fill.
	 */
	private function harvestSchemaChanges(): void {
		foreach ($this->recordedTables as $table) {
			if (property_exists($this, 'added')) {
				foreach ($table->addedColumns() as $name => $column) {
					$this->added[$name] = [$column->rawType(), $column->options()];
				}
			}

			if (property_exists($this, 'indexes')) {
				foreach ($table->addedIndexes() as $index) {
					$this->indexes[] = [$index['columns'], $index['name'], $index['unique']];
				}
			}

			if (property_exists($this, 'primaryKey') && $table->addedPrimaryKey() !== null) {
				$this->primaryKey = $table->addedPrimaryKey();
			}
		}
	}

	/**
	 * The same, for a step that creates several tables: every shape is keyed by
	 * table name, so one table's index is never read as another's.
	 */
	private function harvestSchemaChangesByTable(): void {
		foreach ($this->recordedTables as $table) {
			$name = $table->getName();

			if (property_exists($this, 'added')) {
				$this->added[$name] = [];
				foreach ($table->addedColumns() as $column => $definition) {
					$this->added[$name][$column] = [$definition->rawType(), $definition->options()];
				}
			}

			if (property_exists($this, 'indexes')) {
				$this->indexes[$name] = [];
				foreach ($table->addedIndexes() as $index) {
					$this->indexes[$name][] = [$index['columns'], $index['name'], $index['unique']];
				}
			}

			// the property is `primaryKeys` in most of these tests and
			// `primaryKey` in one; either way it is keyed by table here
			if (property_exists($this, 'primaryKeys')) {
				$this->primaryKeys[$name] = $table->addedPrimaryKey() ?? [];
			} elseif (property_exists($this, 'primaryKey')) {
				$this->primaryKey[$name] = $table->addedPrimaryKey() ?? [];
			}
		}
	}
}
