<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Helper;

use DateTime;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\DateType;
use Doctrine\DBAL\Types\DecimalType;
use Doctrine\DBAL\Types\FloatType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\SmallIntType;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Writes one row into any of the app's tables from the installed schema:
 * the columns a test names get its values, every other column (but an
 * autoincrement) something random enough not to collide with a unique
 * index. For tests that are about which rows a statement reaches, not about
 * what the rows say.
 */
final class RowSeeder {
	/** @var array<string, Table> */
	private static array $tables = [];

	public static function table(string $name): Table {
		if (!isset(self::$tables[$name])) {
			$prefix = Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
			self::$tables[$name] = Server::get(IDBConnection::class)->createSchema()->getTable($prefix . $name);
		}

		return self::$tables[$name];
	}

	/** @param array<string, mixed> $values */
	public static function insert(string $table, array $values): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->insert($table);
		foreach (self::table($table)->getColumns() as $column) {
			$name = $column->getName();
			if (array_key_exists($name, $values)) {
				$qb->setValue($name, $qb->createNamedParameter($values[$name], self::typeOf($values[$name])));
			} elseif (!$column->getAutoincrement()) {
				[$value, $type] = self::filler($column);
				$qb->setValue($name, $qb->createNamedParameter($value, $type));
			}
		}
		$qb->executeStatement();
	}

	/**
	 * Many rows, in one transaction: a commit per row is an fsync per row on
	 * SQLite, which makes thousands of them minutes.
	 *
	 * @param iterable<array<string, mixed>> $rows
	 */
	public static function insertAll(string $table, iterable $rows): void {
		$connection = Server::get(IDBConnection::class);
		$connection->beginTransaction();
		try {
			foreach ($rows as $values) {
				self::insert($table, $values);
			}
			$connection->commit();
		} catch (\Throwable $e) {
			$connection->rollBack();

			throw $e;
		}
	}

	/**
	 * Deletes the rows whose `$column` is one of `$values`, a thousand at a
	 * time.
	 *
	 * @param list<int|string> $values
	 */
	public static function deleteIn(string $table, string $column, array $values): void {
		foreach (array_chunk($values, 1000) as $chunk) {
			$qb = Server::get(IDBConnection::class)->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in(
				$column,
				$qb->createNamedParameter($chunk, is_int($chunk[0]) ? IQueryBuilder::PARAM_INT_ARRAY : IQueryBuilder::PARAM_STR_ARRAY)
			));
			$qb->executeStatement();
		}
	}

	/** @param array<string, mixed> $where column => value, all of them */
	public static function count(string $table, array $where): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from($table);
		foreach ($where as $column => $value) {
			$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value, self::typeOf($value))));
		}
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/** @param array<string, mixed> $where column => value, all of them */
	public static function delete(string $table, array $where): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete($table);
		foreach ($where as $column => $value) {
			$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value, self::typeOf($value))));
		}
		$qb->executeStatement();
	}

	private static function typeOf(mixed $value): int|string {
		return match (true) {
			$value instanceof \DateTimeInterface => IQueryBuilder::PARAM_DATE,
			is_bool($value) => IQueryBuilder::PARAM_BOOL,
			is_int($value) => IQueryBuilder::PARAM_INT,
			$value === null => IQueryBuilder::PARAM_NULL,
			default => IQueryBuilder::PARAM_STR,
		};
	}

	/** @return array{0: mixed, 1: int|string} */
	private static function filler(Column $column): array {
		$type = $column->getType();

		return match (true) {
			$type instanceof BooleanType => [false, IQueryBuilder::PARAM_BOOL],
			$type instanceof IntegerType, $type instanceof SmallIntType => [random_int(1, 30000), IQueryBuilder::PARAM_INT],
			$type instanceof BigIntType => [random_int(1, PHP_INT_MAX >> 1), IQueryBuilder::PARAM_INT],
			$type instanceof FloatType, $type instanceof DecimalType => ['0', IQueryBuilder::PARAM_STR],
			$type instanceof DateTimeType, $type instanceof DateType => [new DateTime('@' . time()), IQueryBuilder::PARAM_DATE],
			default => [substr(bin2hex(random_bytes(16)), 0, max(1, min(32, $column->getLength() ?? 32))), IQueryBuilder::PARAM_STR],
		};
	}
}
