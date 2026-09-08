<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\RequestQueueService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The federation delivery queue against the real social_req_queue table: a queued
 * activity goes standby → running → gone on success, failures count up and are
 * retried until the queue abandons them, and a worker that died mid-delivery has
 * its RUNNING rows reaped back to standby. This is the machinery whose earlier
 * regressions (rows kept forever, deliveries silently lost) only show on a real
 * database.
 */
class RequestQueueLifecycleTest extends TestCase {
	private const AUTHOR = 'https://cloud.example.org/qtest/users/author';
	private const INBOX = 'https://remote.example/qtest/inbox';

	private RequestQueueService $service;
	private RequestQueueRequest $request;

	protected function setUp(): void {
		parent::setUp();
		$this->service = Server::get(RequestQueueService::class);
		$this->request = Server::get(RequestQueueRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->request->deleteByAuthor(self::AUTHOR);
	}

	private function enqueue(): string {
		$note = new Note();
		$note->setId(self::AUTHOR . '/notes/' . bin2hex(random_bytes(4)));

		return $this->service->generateRequestQueue(
			[new InstancePath(self::INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW)],
			$note,
			self::AUTHOR
		);
	}

	public function testSuccessfulDeliveryLeavesNoRow(): void {
		$token = $this->enqueue();

		$requests = $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY);
		$this->assertCount(1, $requests);

		$queue = $requests[0];
		$this->service->initRequest($queue);
		$this->assertSame([], $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY), 'running, not standby');

		$this->service->endRequest($queue, true);
		$this->assertSame([], $this->service->getRequestFromToken($token), 'a delivered request is deleted, not kept as a status-9 row');
	}

	public function testAFailureGoesBackToStandbyWithOneMoreTry(): void {
		$token = $this->enqueue();
		$queue = $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY)[0];

		$this->service->initRequest($queue);
		$this->service->endRequest($queue, false);

		$again = $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY);
		$this->assertCount(1, $again);
		$this->assertSame(1, $again[0]->getTries(), 'the failure was counted');
	}

	public function testADeadHostIsAbandonedAfterMaxTries(): void {
		$token = $this->enqueue();

		for ($i = 0; $i < RequestQueueService::MAX_TRIES; $i++) {
			$requests = $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY);
			$this->assertCount(1, $requests, 'retry ' . $i . ' still standby');
			$this->service->initRequest($requests[0]);
			$this->service->endRequest($requests[0], false);
		}

		// listing the standby queue is what abandons exhausted requests
		$this->service->getRequestStandby();
		$this->assertSame(
			[],
			$this->service->getRequestFromToken($token),
			'after MAX_TRIES the request is abandoned (deleted), not retried forever'
		);
	}

	public function testAStaleRunningRowIsReapedBackToStandby(): void {
		$token = $this->enqueue();
		$queue = $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY)[0];
		$this->service->initRequest($queue);

		// a fresh RUNNING row is left alone — the worker may still be delivering
		$this->service->reapStaleRunning();
		$this->assertSame([], $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY));

		// backdate it past the stale threshold, as if the worker died mid-delivery
		$connection = Server::get(\OCP\IDBConnection::class);
		$qb = $connection->getQueryBuilder();
		$qb->update('social_req_queue')
			->set('last', $qb->createNamedParameter(
				new \DateTime('@' . (time() - RequestQueueService::STALE_RUNNING_SECONDS - 60)),
				\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_DATE
			))
			->where($qb->expr()->eq('author', $qb->createNamedParameter(self::AUTHOR)));
		$qb->executeStatement();

		$this->assertGreaterThan(0, $this->service->reapStaleRunning());
		$this->assertCount(
			1,
			$this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY),
			'a delivery whose worker died is retried instead of silently lost'
		);
	}

	// what the administration page reads off this table

	public function testTheQueueCountsItselfByState(): void {
		$token = $this->enqueue();
		$queue = $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY)[0];

		$before = $this->request->countByStatus();
		$this->service->initRequest($queue);
		$after = $this->request->countByStatus();

		$this->assertSame(
			($before[RequestQueue::STATUS_STANDBY] ?? 0) - 1,
			$after[RequestQueue::STATUS_STANDBY] ?? 0
		);
		$this->assertSame(
			($before[RequestQueue::STATUS_RUNNING] ?? 0) + 1,
			$after[RequestQueue::STATUS_RUNNING] ?? 0
		);
	}

	public function testFailingDeliveriesComeBackWorstFirst(): void {
		$fresh = $this->service->getRequestFromToken($this->enqueue())[0];
		$struggling = $this->service->getRequestFromToken($this->enqueue())[0];

		// one attempt that failed, then another
		$this->service->initRequest($struggling);
		$this->service->endRequest($struggling, false);
		$this->service->initRequest($struggling);
		$this->service->endRequest($struggling, false);

		// the table is shared with whatever else this instance is trying to
		// deliver, so pick out our own row rather than assuming a position
		$failing = $this->request->getFailing();
		$ours = array_values(array_filter(
			$failing,
			fn (RequestQueue $request): bool => $request->getId() === $struggling->getId()
		));

		$this->assertCount(1, $ours);
		$this->assertSame(2, $ours[0]->getTries());
		$this->assertSame(self::INBOX, $ours[0]->getInstance()->getUri(), 'the address survives the round trip');

		$this->assertNotContains(
			$fresh->getId(),
			array_map(fn (RequestQueue $request): int => $request->getId(), $failing),
			'a delivery that never failed is not failing'
		);

		$tries = array_map(fn (RequestQueue $request): int => $request->getTries(), $failing);
		$sorted = $tries;
		rsort($sorted);
		$this->assertSame($sorted, $tries, 'worst first, so the top of the table is the worst news');
	}

	public function testTheFailureThresholdIsRespected(): void {
		$queue = $this->service->getRequestFromToken($this->enqueue())[0];
		$this->service->initRequest($queue);
		$this->service->endRequest($queue, false);

		$this->assertNotContains(
			$queue->getId(),
			array_map(fn (RequestQueue $r): int => $r->getId(), $this->request->getFailing(5)),
			'one failure is not five'
		);
	}
}
