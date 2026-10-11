<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The query counter itself, on the real connection: a budget test whose
 * counter saw nothing would pass for ever.
 */
class QueryCounterTest extends TestCase {
	public function testEveryStatementTheConnectionExecutesIsRecorded(): void {
		$connection = Server::get(IDBConnection::class);
		$inner = QueryCounter::doctrineConnection();
		$executedBefore = method_exists($inner, 'getStats') ? $inner->getStats()['executed'] : null;

		$run = QueryCounter::record(function () use ($connection): void {
			for ($i = 0; $i < 2; $i++) {
				$qb = $connection->getQueryBuilder();
				$qb->select('nid')->from(CoreRequestBuilder::TABLE_STREAM)->setMaxResults(1);
				$qb->executeQuery()->closeCursor();
			}
			$qb = $connection->getQueryBuilder();
			$qb->update(CoreRequestBuilder::TABLE_STREAM)
				->set('content', $qb->createNamedParameter('never'))
				->where($qb->expr()->eq('nid', $qb->createNamedParameter(-1)));
			$qb->executeStatement();
		});

		$this->assertSame(3, $run->count(), $run->summary());
		$this->assertCount(2, $run->matching('/^SELECT .*social_stream/i'));
		$this->assertCount(1, $run->matching('/^UPDATE .*social_stream/i'));
		if ($executedBefore !== null) {
			$this->assertSame(
				$executedBefore + 3, $inner->getStats()['executed'],
				'the server\'s own counter saw the same number of statements'
			);
		}
	}

	public function testARequestClassQueryIsSeenWithTheParametersItWasBoundWith(): void {
		$missing = 'https://query-counter.example/users/' . bin2hex(random_bytes(4));

		$run = QueryCounter::record(function () use ($missing): void {
			try {
				Server::get(CacheActorsRequest::class)->getFromId($missing);
			} catch (CacheActorDoesNotExistException) {
			}
		});

		$reads = $run->matching('/social_cache_actor/');
		$this->assertNotEmpty($reads, $run->summary());
		// actor ids are looked up by their md5, the `id_prim` column
		$this->assertContains(
			md5($missing), array_merge(...array_map(static fn (array $q): array => array_values($q['params']), $reads)),
			'the parameters are recorded with the statement'
		);
	}

	/** What the plan tests depend on: a recorded statement runs again as it is. */
	public function testARecordedStatementCanBeExecutedAgainVerbatim(): void {
		$connection = Server::get(IDBConnection::class);
		$run = QueryCounter::record(function () use ($connection): int {
			$qb = $connection->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'total'))
				->from(CoreRequestBuilder::TABLE_STREAM)
				->where($qb->expr()->gt('nid', $qb->createNamedParameter(0)));
			$cursor = $qb->executeQuery();
			$total = (int)$cursor->fetchOne();
			$cursor->closeCursor();

			return $total;
		});

		[$query] = $run->queries();
		$again = QueryCounter::doctrineConnection()->executeQuery($query['sql'], $query['params'], $query['types']);

		$this->assertSame($run->result(), (int)$again->fetchOne());
	}

	public function testTheLoggerInPlaceBeforeIsPutBackEvenWhenTheBlockThrows(): void {
		$configuration = QueryCounter::doctrineConnection()->getConfiguration();
		$before = $configuration->getSQLLogger();

		try {
			QueryCounter::record(static function (): void {
				throw new RuntimeException('the block failed');
			});
			$this->fail('the exception was swallowed');
		} catch (RuntimeException $e) {
			$this->assertSame('the block failed', $e->getMessage());
		}

		$this->assertSame($before, $configuration->getSQLLogger());
	}
}
