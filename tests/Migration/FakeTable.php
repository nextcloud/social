<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use LogicException;
use OCP\DB\Schema\ColumnType;
use OCP\DB\Schema\IColumn;
use OCP\DB\Schema\IIndex;
use OCP\DB\Schema\ITable;

/**
 * The schema a migration asked for, recorded instead of built.
 *
 * Each migration test used to carry its own anonymous table class with the
 * two or three methods that test happened to need. Nextcloud 35 gave
 * `ISchemaWrapper::getTable()` and `createTable()` the return type `ITable`,
 * which those ad-hoc doubles do not satisfy, so this is one double that does
 * — and one place to update when the interface next moves.
 *
 * What the DDL becomes on each platform is verified against the real DBAL
 * elsewhere; what these tests check is the schema the step asks for.
 */
class FakeTable implements ITable {
	/** @var array<string, FakeColumn> */
	private array $columns = [];
	/** @var list<array{columns: list<string>, name: ?string, unique: bool}> */
	private array $indexes = [];
	/** @var list<string>|null */
	private ?array $primaryKey = null;
	/** @var list<string> */
	private array $dropped = [];

	/**
	 * @param list<string> $existingColumns columns the table already has
	 * @param list<string> $existingIndexes indexes the table already has
	 */
	public function __construct(
		private string $name = 'fake',
		private array $existingColumns = [],
		private array $existingIndexes = [],
	) {
	}

	// --- what the tests read back -------------------------------------------

	/** @return array<string, FakeColumn> */
	public function addedColumns(): array {
		return $this->columns;
	}

	/** @return array{0: string|ColumnType, 1: array} type and options, as passed */
	public function addedColumn(string $name): array {
		if (!isset($this->columns[$name])) {
			throw new LogicException('the migration never added a column named ' . $name);
		}

		return [$this->columns[$name]->rawType(), $this->columns[$name]->options()];
	}

	/** @return list<array{columns: list<string>, name: ?string, unique: bool}> */
	public function addedIndexes(): array {
		return $this->indexes;
	}

	/** @return list<string>|null */
	public function addedPrimaryKey(): ?array {
		return $this->primaryKey;
	}

	/** @return list<string> */
	public function droppedColumns(): array {
		return $this->dropped;
	}

	// --- ITable --------------------------------------------------------------

	public function getName(): string {
		return $this->name;
	}

	public function setPrimaryKey(array $columnNames, string|false $indexName = false): self {
		$this->primaryKey = $columnNames;

		return $this;
	}

	public function addIndex(array $columnNames, ?string $indexName = null, array $flags = [], array $options = []): self {
		$this->indexes[] = ['columns' => $columnNames, 'name' => $indexName, 'unique' => false];

		return $this;
	}

	public function addUniqueIndex(array $columnNames, ?string $indexName = null, array $options = []): self {
		$this->indexes[] = ['columns' => $columnNames, 'name' => $indexName, 'unique' => true];

		return $this;
	}

	public function addColumn(string $name, string|ColumnType $typeName, array $options = []): IColumn {
		return $this->columns[$name] = new FakeColumn($name, $typeName, $options);
	}

	public function hasColumn(string $name): bool {
		return isset($this->columns[$name]) || in_array($name, $this->existingColumns, true);
	}

	public function getColumn(string $name): IColumn {
		if (!isset($this->columns[$name])) {
			// a column the table already had, which the step is about to change
			$this->columns[$name] = new FakeColumn($name, ColumnType::String);
		}

		return $this->columns[$name];
	}

	public function dropColumn(string $name): self {
		$this->dropped[] = $name;
		unset($this->columns[$name]);

		return $this;
	}

	public function hasIndex(string $name): bool {
		foreach ($this->indexes as $index) {
			if ($index['name'] === $name) {
				return true;
			}
		}

		return in_array($name, $this->existingIndexes, true);
	}

	public function getColumns(): array {
		return array_values($this->columns);
	}

	public function hasPrimaryKey(): bool {
		return $this->primaryKey !== null;
	}

	public function modifyColumn(string $name, array $options): self {
		throw new LogicException('modifyColumn() is not part of this double');
	}

	public function dropPrimaryKey(): self {
		throw new LogicException('dropPrimaryKey() is not part of this double');
	}

	public function getPrimaryKey(): ?IIndex {
		throw new LogicException('getPrimaryKey() is not part of this double');
	}

	public function dropIndex(string $name): self {
		throw new LogicException('dropIndex() is not part of this double');
	}

	public function renameIndex(string $oldName, ?string $newName = null): self {
		throw new LogicException('renameIndex() is not part of this double');
	}

	public function addUniqueConstraint(array $columnNames, ?string $indexName = null, array $flags = [], array $options = []): self {
		throw new LogicException('addUniqueConstraint() is not part of this double');
	}

	public function hasUniqueConstraint(string $name): bool {
		throw new LogicException('hasUniqueConstraint() is not part of this double');
	}

	public function removeUniqueConstraint(string $name): void {
		throw new LogicException('removeUniqueConstraint() is not part of this double');
	}

	public function getIndexes(): array {
		throw new LogicException('getIndexes() is not part of this double');
	}

	public function getIndex(string $name): IIndex {
		throw new LogicException('getIndex() is not part of this double');
	}

	public function addForeignKeyConstraint(ITable|string $foreignTable, array $localColumnNames, array $foreignColumnNames, array $options = [], ?string $name = null): self {
		throw new LogicException('addForeignKeyConstraint() is not part of this double');
	}

	public function hasForeignKey(string $name): bool {
		throw new LogicException('hasForeignKey() is not part of this double');
	}

	public function removeForeignKey(string $name): void {
		throw new LogicException('removeForeignKey() is not part of this double');
	}

	public function getForeignKeys(): array {
		throw new LogicException('getForeignKeys() is not part of this double');
	}
}
