<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use DateTime;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Db\StreamQueueRequest;
use OCA\Social\Model\StreamQueue;
use OCA\Social\Service\RequestQueueService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * What a queue drain picks up, against the real database.
 *
 * The retry backoff and the give-up threshold used to be applied in PHP over
 * the oldest N rows, which means the rows of one unreachable instance sit in
 * that window forever and nothing behind them is ever delivered. Both are now
 * part of the query, and these are the cases that go wrong if the SQL is wrong
 * — including the boolean/integer literal handling that differs between
 * sqlite, MySQL and PostgreSQL.
 */
class QueueDrainTest extends TestCase {
	private const TOKEN = 'itest-queue-drain';

	private RequestQueueRequest $requestQueue;
	private StreamQueueRequest $streamQueue;
	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->requestQueue = Server::get(RequestQueueRequest::class);
		$this->streamQueue = Server::get(StreamQueueRequest::class);
		$this->connection = Server::get(IDBConnection::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([CoreRequestBuilder::TABLE_REQUEST_QUEUE, CoreRequestBuilder::TABLE_STREAM_QUEUE] as $table) {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('token', $qb->createNamedParameter(self::TOKEN)));
			$qb->executeStatement();
		}
	}

	/** @param int|null $lastSecondsAgo null for a row that was never attempted */
	private function request(string $host, int $tries, ?int $lastSecondsAgo, int $priority = 1): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert(CoreRequestBuilder::TABLE_REQUEST_QUEUE)
			->setValue('token', $qb->createNamedParameter(self::TOKEN))
			->setValue('author', $qb->createNamedParameter('https://local.example/users/alice'))
			->setValue('author_prim', $qb->createNamedParameter(md5('https://local.example/users/alice')))
			->setValue('activity', $qb->createNamedParameter('{}'))
			->setValue('instance', $qb->createNamedParameter(json_encode(['uri' => $host])))
			->setValue('priority', $qb->createNamedParameter($priority, IQueryBuilder::PARAM_INT))
			->setValue('status', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->setValue('tries', $qb->createNamedParameter($tries, IQueryBuilder::PARAM_INT));

		if ($lastSecondsAgo !== null) {
			$qb->setValue('last', $qb->createNamedParameter(
				new DateTime('@' . (time() - $lastSecondsAgo)), IQueryBuilder::PARAM_DATE
			));
		}

		$qb->executeStatement();
	}

	/** @return string[] the instance uri of every request the drain took */
	private function drained(): array {
		$hosts = [];
		foreach ($this->requestQueue->getStandby() as $request) {
			if ($request->getToken() !== self::TOKEN) {
				continue;
			}
			$hosts[] = $request->getInstance()->getUri();
		}

		return $hosts;
	}

	public function testARequestThatHasNeverBeenAttemptedIsDue(): void {
		// `last` is NULL there, and a naive `last < cutoff` drops it silently
		$this->request('never.example', 0, null);

		$this->assertSame(['never.example'], $this->drained());
	}

	public function testARequestWithinItsBackoffIsLeftAlone(): void {
		// tries = 4 waits 4^4 + 15 = 271 seconds (RequestQueueService::retryDelay);
		// 200 seconds ago was already due on the old tries^4/3 schedule (85 s)
		$this->request('waiting.example', 4, 200);

		$this->assertSame([], $this->drained());
	}

	public function testARequestPastItsBackoffComesBack(): void {
		$this->request('ready.example', 4, 600);

		$this->assertSame(['ready.example'], $this->drained());
	}

	public function testTheRowsOfADeadInstanceDoNotFillTheWindow(): void {
		// the window is RequestQueueRequest::STANDBY_BATCH; fill it past that
		// with requests that are not due, and the one that is must still come
		// back — filtering in PHP is exactly what made this fail
		for ($i = 0; $i < RequestQueueRequest::STANDBY_BATCH + 10; $i++) {
			$this->request('dead.example', 6, 5);
		}
		$this->request('alive.example', 0, null);

		$this->assertSame(['alive.example'], $this->drained());
	}

	public function testAnExhaustedRequestIsDroppedRatherThanKept(): void {
		$this->request('gone.example', RequestQueueService::MAX_TRIES, 100000);

		$this->assertSame([], $this->drained());
		$this->assertSame(0, $this->countRows(CoreRequestBuilder::TABLE_REQUEST_QUEUE));
	}

	public function testTheMostUrgentRequestIsDrainedFirst(): void {
		$this->request('low.example', 0, 3600, 0);
		$this->request('top.example', 0, 60, 3);

		$this->assertSame(['top.example', 'low.example'], $this->drained());
	}

	private function countRows(string $table): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->from($table)
			->where($qb->expr()->eq('token', $qb->createNamedParameter(self::TOKEN)));
		$cursor = $qb->executeQuery();
		$count = (int)($cursor->fetch()['count'] ?? 0);
		$cursor->closeCursor();

		return $count;
	}

	// --- the stream (caching) queue

	private function item(int $tries, ?int $lastSecondsAgo, int $status = StreamQueue::STATUS_STANDBY): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert(CoreRequestBuilder::TABLE_STREAM_QUEUE)
			->setValue('token', $qb->createNamedParameter(self::TOKEN))
			->setValue('stream_id', $qb->createNamedParameter('https://remote.example/notes/1'))
			->setValue('type', $qb->createNamedParameter(StreamQueue::TYPE_CACHE))
			->setValue('status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_INT))
			->setValue('tries', $qb->createNamedParameter($tries, IQueryBuilder::PARAM_INT));

		if ($lastSecondsAgo !== null) {
			$qb->setValue('last', $qb->createNamedParameter(
				new DateTime('@' . (time() - $lastSecondsAgo)), IQueryBuilder::PARAM_DATE
			));
		}

		$qb->executeStatement();

		return (int)$qb->getLastInsertId();
	}

	private function ourStandbyItems(): array {
		return array_values(array_filter(
			$this->streamQueue->getStandby(),
			fn (StreamQueue $queue): bool => $queue->getToken() === self::TOKEN
		));
	}

	public function testTheCachingQueueAppliesTheSameBackoff(): void {
		$this->item(0, null);
		$this->item(5, 10);

		$this->assertCount(1, $this->ourStandbyItems());
	}

	public function testAnExhaustedCachingItemIsDropped(): void {
		$this->item(StreamQueueRequest::MAX_TRIES, 100000);

		$this->assertSame([], $this->ourStandbyItems());
		$this->assertSame(0, $this->countRows(CoreRequestBuilder::TABLE_STREAM_QUEUE));
	}

	public function testACachedItemLeavesTheTableRatherThanStayingForever(): void {
		$this->item(0, null, StreamQueue::STATUS_RUNNING);
		$queue = $this->streamQueue->getFromToken(self::TOKEN)[0];

		$this->streamQueue->setAsSuccess($queue);

		$this->assertSame(0, $this->countRows(CoreRequestBuilder::TABLE_STREAM_QUEUE));
	}

	public function testAFailedItemSaysItIsBackOnStandby(): void {
		$this->item(0, null, StreamQueue::STATUS_RUNNING);
		$queue = $this->streamQueue->getFromToken(self::TOKEN)[0];

		$this->streamQueue->setAsFailure($queue);

		// the row is on standby with one more try; the model used to claim
		// STATUS_SUCCESS
		$this->assertSame(StreamQueue::STATUS_STANDBY, $queue->getStatus());
		$this->assertSame(1, $this->streamQueue->getFromToken(self::TOKEN)[0]->getTries());
	}
}
