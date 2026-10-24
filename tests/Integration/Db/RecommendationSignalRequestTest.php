<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\RecommendationSignalRequest;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

class RecommendationSignalRequestTest extends TestCase {
	private RecommendationSignalRequest $request;
	private IDBConnection $connection;
	private string $actorKey;
	private string $postKey;
	private int $now;

	protected function setUp(): void {
		parent::setUp();
		$this->request = Server::get(RecommendationSignalRequest::class);
		$this->connection = Server::get(IDBConnection::class);
		$this->actorKey = 'recommendation-exposure-' . bin2hex(random_bytes(8));
		$this->postKey = hash('sha256', 'recommendation-exposure-post');
		$this->now = time();
	}

	protected function tearDown(): void {
		$this->deleteTestRows();
		parent::tearDown();
	}

	public function testExposureReadAggregatesOnlyFreshRowsInTheCurrentGeneration(): void {
		$this->insertSeen('f7f454f1-d33d-4fb5-a132-335837049d23', 7, 2, $this->now - 3600);
		$this->insertSeen('f7f454f1-d33d-4fb5-a132-335837049d24', 7, 3, $this->now - 1800);
		$this->insertSeen('f7f454f1-d33d-4fb5-a132-335837049d25', 6, 9, $this->now - 1200);
		$this->insertSeen('f7f454f1-d33d-4fb5-a132-335837049d26', 7, 5, $this->now - 30 * 86400 - 1);

		$result = $this->request->exposures(
			$this->actorKey, 7, [$this->postKey, hash('sha256', 'not-seen')], $this->now - 30 * 86400
		);

		$this->assertSame([
			$this->postKey => [
				'f7f454f1-d33d-4fb5-a132-335837049d23' => ['impressions' => 2, 'updated_at' => $this->now - 3600],
				'f7f454f1-d33d-4fb5-a132-335837049d24' => ['impressions' => 3, 'updated_at' => $this->now - 1800],
			],
		], $result);
	}

	private function insertSeen(string $sessionKey, int $generation, int $impressions, int $updatedAt): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert(RecommendationSignalRequest::TABLE_SEEN)
			->setValue('actor_key', $qb->createNamedParameter($this->actorKey))
			->setValue('post_key', $qb->createNamedParameter($this->postKey))
			->setValue('session_key', $qb->createNamedParameter($sessionKey))
			->setValue('generation', $qb->createNamedParameter($generation, IQueryBuilder::PARAM_INT))
			->setValue('impressions', $qb->createNamedParameter($impressions, IQueryBuilder::PARAM_INT))
			->setValue('updated_at', $qb->createNamedParameter($updatedAt, IQueryBuilder::PARAM_INT))
			->executeStatement();
	}

	private function deleteTestRows(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete(RecommendationSignalRequest::TABLE_SEEN)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($this->actorKey)))
			->executeStatement();
	}
}
