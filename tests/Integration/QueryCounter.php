<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Logging\SQLLogger;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\Assert;

/**
 * The SQL the server's database connection executed while a block ran.
 *
 * Every Request class in the app talks to the same `IDBConnection` the
 * container hands out, and behind it to one Doctrine connection. Doctrine
 * reports each statement to the SQL logger on its configuration, read afresh
 * for every statement, so swapping a recorder in for the length of a block
 * and putting the previous logger back afterwards sees every query the app
 * makes — through the query builder or not — without touching the services
 * that already hold the connection.
 *
 * ```php
 * $run = QueryCounter::record(fn () => $controller->timelines('home'));
 * $this->assertLessThanOrEqual(12, $run->count(), $run->summary());
 * ```
 */
final class QueryCounter {
	/** @var list<array{sql: string, params: array, types: array}> */
	private array $queries = [];

	private mixed $result = null;

	/**
	 * Runs `$block` and records every statement executed while it ran.
	 *
	 * Skips the calling test where the server's Doctrine has no SQL logger to
	 * hook (DBAL 4), rather than reporting a count of zero.
	 */
	public static function record(callable $block): self {
		$connection = self::doctrineConnection();
		$configuration = $connection->getConfiguration();
		if (!interface_exists(SQLLogger::class) || !method_exists($configuration, 'setSQLLogger')) {
			Assert::markTestSkipped('this server\'s Doctrine DBAL has no SQL logger to record queries with');
		}

		$recorder = new QueryRecorder();
		$previous = $configuration->getSQLLogger();
		$configuration->setSQLLogger($recorder);
		try {
			$result = $block();
		} finally {
			$configuration->setSQLLogger($previous);
		}

		$run = new self();
		$run->queries = $recorder->queries;
		$run->result = $result;

		return $run;
	}

	/**
	 * The Doctrine connection behind the container's `IDBConnection`, which is
	 * what runs the statements.
	 */
	public static function doctrineConnection(): Connection {
		$adapter = Server::get(IDBConnection::class);
		$inner = method_exists($adapter, 'getInner') ? $adapter->getInner() : $adapter;
		Assert::assertInstanceOf(Connection::class, $inner, 'the server\'s IDBConnection is not backed by Doctrine');

		return $inner;
	}

	/** What the block returned. */
	public function result(): mixed {
		return $this->result;
	}

	public function count(): int {
		return count($this->queries);
	}

	/** @return list<array{sql: string, params: array, types: array}> */
	public function queries(): array {
		return $this->queries;
	}

	/**
	 * The statements whose SQL matches `$pattern`, a regular expression.
	 *
	 * @return list<array{sql: string, params: array, types: array}>
	 */
	public function matching(string $pattern): array {
		return array_values(array_filter(
			$this->queries,
			static fn (array $query): bool => preg_match($pattern, $query['sql']) === 1
		));
	}

	/**
	 * The statements, numbered and shortened, for a failure message: a budget
	 * that is exceeded should say which query is the new one.
	 */
	public function summary(): string {
		$lines = [];
		foreach ($this->queries as $i => $query) {
			$sql = preg_replace('/\s+/', ' ', $query['sql']) ?? $query['sql'];
			$lines[] = sprintf('%3d. %s', $i + 1, mb_strimwidth($sql, 0, 220, '…'));
		}

		return count($this->queries) . " statements:\n" . implode("\n", $lines);
	}
}
