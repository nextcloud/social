<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\RecommendationDiscoveryHealthRequest;
use OCA\Social\Service\Discovery\PostSourceStats;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

class RecommendationDiscoveryHealthRequestTest extends TestCase {
	private RecommendationDiscoveryHealthRequest $request;
	private IDBConnection $connection;
	private int $bucket;

	protected function setUp(): void {
		parent::setUp();
		$this->request = Server::get(RecommendationDiscoveryHealthRequest::class);
		$this->connection = Server::get(IDBConnection::class);
		$this->bucket = intdiv(time(), 3600) * 3600;
		$this->deleteTestRows();
	}

	protected function tearDown(): void {
		$this->deleteTestRows();
		parent::tearDown();
	}

	public function testSourceCountersAccumulateByHourAndKeepNoQueryIdentity(): void {
		$this->request->record('activitypub', new PostSourceStats(2, 1, 1, 5, 3, 120), $this->bucket + 10);
		$this->request->record('activitypub', new PostSourceStats(1, 0, 0, 2, 1, 180), $this->bucket + 20);
		$summary = $this->request->summary($this->bucket);

		$this->assertSame(2, $summary['activitypub']['calls']);
		$this->assertSame(3, $summary['activitypub']['requests']);
		$this->assertSame(1, $summary['activitypub']['failed_requests']);
		$this->assertSame(1, $summary['activitypub']['rate_limited']);
		$this->assertSame(7, $summary['activitypub']['posts_seen']);
		$this->assertSame(4, $summary['activitypub']['posts_stored']);
		$this->assertSame(150, $summary['activitypub']['average_duration_ms']);
		$this->assertArrayNotHasKey('query', $summary['activitypub']);
	}

	private function deleteTestRows(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete(RecommendationDiscoveryHealthRequest::TABLE)
			->where($qb->expr()->in('provider', $qb->createNamedParameter(['activitypub', 'atproto'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->eq('hour_bucket', $qb->createNamedParameter($this->bucket, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
