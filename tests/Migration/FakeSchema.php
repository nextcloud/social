<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use LogicException;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\ColumnType;
use OCP\DB\Schema\ITable;

/**
 * A schema that remembers, so migrations can be replayed onto it in order.
 *
 * {@see FakeTable} records what one step asked of one table. This holds those
 * tables between steps, which is what lets the whole history be run end to end
 * and the shape it arrives at be read off — `hasTable()` answers for a table an
 * earlier step created, and `hasColumn()` for a column an earlier step added.
 *
 * Only the operations the app's own migrations use are implemented, and the
 * rest throw rather than quietly answering something plausible: a migration
 * reaching for one of those is a migration this double has stopped modelling,
 * and that is worth failing over.
 */
class FakeSchema implements ISchemaWrapper {
	/** @var array<string, FakeTable> */
	private array $tables = [];

	public function hasTable(string $tableName): bool {
		return isset($this->tables[$tableName]);
	}

	public function createTable(string $tableName): ITable {
		if (isset($this->tables[$tableName])) {
			throw new LogicException($tableName . ' is created twice');
		}

		return $this->tables[$tableName] = new FakeTable($tableName);
	}

	public function getTable(string $tableName): ITable {
		if (!isset($this->tables[$tableName])) {
			throw new LogicException($tableName . ' is read before it is created');
		}

		return $this->tables[$tableName];
	}

	public function dropTable(string $tableName): self {
		unset($this->tables[$tableName]);

		return $this;
	}

	/** @return array<string, FakeTable> every table, keyed and sorted by name */
	public function tables(): array {
		$tables = $this->tables;
		ksort($tables);

		return $tables;
	}

	/** @return array<string, FakeTable> every table, in the order it was created */
	public function tablesAsCreated(): array {
		return $this->tables;
	}

	/**
	 * The whole schema as plain values, for comparing one history against
	 * another. Sorted throughout, because two migrations that add the same two
	 * columns in either order arrive at the same table.
	 *
	 * @return array<string, array{columns: array, indexes: array, primary: array}>
	 */
	public function shape(): array {
		$shape = [];
		foreach ($this->tables() as $name => $table) {
			$columns = [];
			foreach ($table->addedColumns() as $column => $definition) {
				$options = $definition->options();
				ksort($options);
				$type = $definition->rawType();
				$columns[$column] = [
					'type' => $type instanceof ColumnType ? $type->value : (string)$type,
					'options' => $options,
				];
			}
			ksort($columns);

			$indexes = array_map(
				static fn (array $index): array => [
					'columns' => $index['columns'],
					'name' => $index['name'],
					'unique' => $index['unique'],
				],
				$table->addedIndexes()
			);
			usort($indexes, static fn (array $a, array $b): int => [$a['name'], $a['columns']] <=> [$b['name'], $b['columns']]);

			$shape[$name] = [
				'columns' => $columns,
				'indexes' => $indexes,
				'primary' => $table->addedPrimaryKey() ?? [],
			];
		}

		return $shape;
	}

	public function getTables(): array {
		return array_values($this->tables);
	}

	public function getTableNames(): array {
		return array_keys($this->tables);
	}

	public function getTableNamesWithoutPrefix(): array {
		return array_keys($this->tables);
	}

	public function getDatabasePlatform(): AbstractPlatform {
		throw new LogicException('no migration in this app asks about the platform');
	}

	public function dropAutoincrementColumn(string $table, string $column): void {
		throw new LogicException('no migration in this app drops an autoincrement column');
	}
}
