<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Closure;
use LogicException;
use OCP\IDBConnection;

/**
 * A database connection that hands out recording query builders.
 *
 * Written out rather than mocked: the unit suite runs against the `nextcloud/ocp`
 * interfaces alone, and generating a double for `IDBConnection` would have to
 * evaluate `IQueryBuilder::PARAM_STR` — a Doctrine constant no dependency of
 * this app provides. Only the two methods a migration or repair step uses are
 * implemented; every other one says so if it is ever called.
 */
class FakeConnection implements IDBConnection {
	/** @var FakeQueryBuilder[] every builder handed out, in the order it was asked for */
	public array $queries = [];

	/**
	 * @param array<int, array<array<string, mixed>>> $rowSets what the successive
	 *                                                         executeQuery() calls hand back; anything beyond the list is no rows
	 * @param ?Closure $onStatement called with each FakeQueryBuilder that runs a
	 *                              write, so a test can make one of them fail
	 */
	public function __construct(
		private array $rowSets = [],
		private ?Closure $onStatement = null,
	) {
	}

	public function getQueryBuilder() {
		$query = new FakeQueryBuilder($this, $this->onStatement);
		$this->queries[] = $query;

		return $query;
	}

	public function escapeLikeParameter(string $param): string {
		return $param;
	}

	/**
	 * The rows the next executeQuery() answers with.
	 *
	 * @return array<array<string, mixed>>
	 */
	public function nextRowSet(): array {
		return array_shift($this->rowSets) ?? [];
	}

	/** The builders that ran a write, in order. */
	public function writes(): array {
		return array_values(array_filter(
			$this->queries,
			static fn (FakeQueryBuilder $query): bool => $query->statements > 0
		));
	}

	public function prepare($sql, $limit = null, $offset = null): \OCP\DB\IPreparedStatement {
		throw new LogicException('prepare() is not part of this double');
	}

	public function executeQuery(string $sql, ?array $params = null, $types = null): \OCP\DB\IResult {
		throw new LogicException('executeQuery() is not part of this double');
	}

	public function executeUpdate(string $sql, ?array $params = null, ?array $types = null): int {
		throw new LogicException('executeUpdate() is not part of this double');
	}

	public function executeStatement($sql, ?array $params = null, ?array $types = null): int {
		throw new LogicException('executeStatement() is not part of this double');
	}

	public function lastInsertId(string $table): int {
		throw new LogicException('lastInsertId() is not part of this double');
	}

	public function insertIfNotExist(string $table, array $input, ?array $compare = null) {
		throw new LogicException('insertIfNotExist() is not part of this double');
	}

	public function insertIgnoreConflict(string $table, array $values): int {
		throw new LogicException('insertIgnoreConflict() is not part of this double');
	}

	public function setValues($table, array $keys, array $values, ?array $updatePreconditionValues = null): int {
		throw new LogicException('setValues() is not part of this double');
	}

	public function lockTable($tableName): void {
		throw new LogicException('lockTable() is not part of this double');
	}

	public function unlockTable(): void {
		throw new LogicException('unlockTable() is not part of this double');
	}

	public function beginTransaction(): void {
		throw new LogicException('beginTransaction() is not part of this double');
	}

	public function inTransaction(): bool {
		throw new LogicException('inTransaction() is not part of this double');
	}

	public function commit(): void {
		throw new LogicException('commit() is not part of this double');
	}

	public function rollBack(): void {
		throw new LogicException('rollBack() is not part of this double');
	}

	public function getError(): string {
		throw new LogicException('getError() is not part of this double');
	}

	public function errorCode() {
		throw new LogicException('errorCode() is not part of this double');
	}

	public function errorInfo() {
		throw new LogicException('errorInfo() is not part of this double');
	}

	public function connect(): bool {
		throw new LogicException('connect() is not part of this double');
	}

	public function close(): void {
		throw new LogicException('close() is not part of this double');
	}

	public function quote($input, $type = null) {
		throw new LogicException('quote() is not part of this double');
	}

	public function getDatabasePlatform() {
		throw new LogicException('getDatabasePlatform() is not part of this double');
	}

	public function dropTable(string $table): void {
		throw new LogicException('dropTable() is not part of this double');
	}

	public function tableExists(string $table): bool {
		throw new LogicException('tableExists() is not part of this double');
	}

	public function supports4ByteText(): bool {
		throw new LogicException('supports4ByteText() is not part of this double');
	}

	public function createSchema(): \Doctrine\DBAL\Schema\Schema {
		throw new LogicException('createSchema() is not part of this double');
	}

	public function migrateToSchema(\Doctrine\DBAL\Schema\Schema $toSchema): void {
		throw new LogicException('migrateToSchema() is not part of this double');
	}

	public function getTypedQueryBuilder(): \OCP\DB\QueryBuilder\ITypedQueryBuilder {
		throw new LogicException('getTypedQueryBuilder() is not part of this double');
	}

	public function truncateTable(string $table, bool $cascade): void {
		throw new LogicException('truncateTable() is not part of this double');
	}

	public function getDatabaseProvider(bool $strict = false): string {
		throw new LogicException('getDatabaseProvider() is not part of this double');
	}

	public function getShardDefinition(string $name): ?\OC\DB\QueryBuilder\Sharded\ShardDefinition {
		throw new LogicException('getShardDefinition() is not part of this double');
	}

	public function getCrossShardMoveHelper(): \OC\DB\QueryBuilder\Sharded\CrossShardMoveHelper {
		throw new LogicException('getCrossShardMoveHelper() is not part of this double');
	}
}
