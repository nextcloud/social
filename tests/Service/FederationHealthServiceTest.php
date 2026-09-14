<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\FederationHealthService;
use OCA\Social\Service\RequestQueueService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FederationHealthServiceTest extends TestCase {
	private RequestQueueRequest|MockObject $requestQueueRequest;
	private FederationHealthService $service;

	protected function setUp(): void {
		$this->requestQueueRequest = $this->createMock(RequestQueueRequest::class);
		$this->service = new FederationHealthService($this->requestQueueRequest);
	}

	private function request(string $host, int $tries, int $last = 0): RequestQueue {
		$queue = new RequestQueue(
			'{}', new InstancePath('https://' . $host . '/inbox', InstancePath::TYPE_GLOBAL), 'alice'
		);
		$queue->setTries($tries);
		$queue->setLast($last);

		return $queue;
	}

	private function queueHolds(array $counts, array $failing): void {
		$this->requestQueueRequest->method('countByStatus')->willReturn($counts);
		$this->requestQueueRequest->method('getFailing')->willReturn($failing);
	}

	public function testAnEmptyQueueIsReportedAsHealthy(): void {
		$this->queueHolds([], []);

		$summary = $this->service->summary();

		$this->assertSame(0, $summary['waiting']);
		$this->assertSame(0, $summary['running']);
		$this->assertSame(0, $summary['failing']);
		$this->assertSame([], $summary['instances']);
		$this->assertFalse($summary['truncated']);
	}

	public function testWaitingAndRunningAreReadFromTheirOwnStates(): void {
		$this->queueHolds(
			[RequestQueue::STATUS_STANDBY => 12, RequestQueue::STATUS_RUNNING => 3],
			[]
		);

		$summary = $this->service->summary();

		$this->assertSame(12, $summary['waiting']);
		$this->assertSame(3, $summary['running']);
	}

	public function testFailingDeliveriesAreGatheredPerInstance(): void {
		$this->queueHolds([], [
			$this->request('slow.example', 2, 1_700_000_000),
			$this->request('slow.example', 5, 1_700_000_500),
			$this->request('gone.example', 12, 1_700_000_900),
		]);

		$summary = $this->service->summary();

		$this->assertSame(3, $summary['failing']);
		// worst first: an administrator reads the top of the table, not all of it
		$this->assertSame([
			['host' => 'gone.example', 'requests' => 1, 'tries' => 12, 'last' => 1_700_000_900],
			['host' => 'slow.example', 'requests' => 2, 'tries' => 5, 'last' => 1_700_000_500],
		], $summary['instances']);
	}

	public function testDeliveriesNearTheCeilingAreCalledOut(): void {
		$this->queueHolds([], [
			$this->request('gone.example', 14),
			$this->request('gone.example', 9),
			$this->request('slow.example', 2),
		]);

		$summary = $this->service->summary();

		// the two that will be dropped soon, not the one that is merely retrying
		$this->assertSame(2, $summary['atRisk']);
		$this->assertSame(RequestQueueService::MAX_TRIES, $summary['maxTries']);
	}

	public function testOnlyTheWorstInstancesAreListed(): void {
		$failing = [];
		for ($i = 0; $i < 25; $i++) {
			$failing[] = $this->request('host' . $i . '.example', $i + 1);
		}
		$this->queueHolds([], $failing);

		$summary = $this->service->summary();

		$this->assertCount(FederationHealthService::TOP_INSTANCES, $summary['instances']);
		$this->assertSame('host24.example', $summary['instances'][0]['host']);
	}

	public function testAQueueTooLongToCountIsReportedAsSuch(): void {
		$failing = [];
		for ($i = 0; $i < 500; $i++) {
			$failing[] = $this->request('gone.example', 3);
		}
		$this->queueHolds([], $failing);

		// saying "500 failing" when the read stopped at 500 would be a lie
		$this->assertTrue($this->service->summary()['truncated']);
	}

	/**
	 * What the setup check asks. Both are zero on a healthy instance: an
	 * abandoned row is one the drain gave up on, and a standby row last tried
	 * more than a day ago is one no drain came back for — the retry schedule
	 * never waits more than fourteen hours.
	 */
	public function testWhatIsStuckIsTheAbandonedRowsAndTheOnesNobodyCameBackFor(): void {
		$this->requestQueueRequest->method('countByStatus')
			->willReturn([RequestQueue::STATUS_ABANDONED => 3, RequestQueue::STATUS_STANDBY => 40]);
		$this->requestQueueRequest->expects($this->once())->method('countStandbyOlderThan')
			->with($this->callback(fn (int $before): bool
				=> abs(time() - FederationHealthService::STALE_STANDBY_SECONDS - $before) < 5))
			->willReturn(7);

		$this->assertSame(['abandoned' => 3, 'stale' => 7], $this->service->stuck());
	}

	public function testAQueueThatHasNeverAbandonedAnythingIsNotStuck(): void {
		$this->requestQueueRequest->method('countByStatus')->willReturn([RequestQueue::STATUS_STANDBY => 4]);
		$this->requestQueueRequest->method('countStandbyOlderThan')->willReturn(0);

		$this->assertSame(['abandoned' => 0, 'stale' => 0], $this->service->stuck());
	}

	public function testARequestWithNowhereToGoIsNotCountedAsAnInstance(): void {
		$nowhere = new RequestQueue('{}', null, 'alice');
		$nowhere->setTries(4);
		$this->queueHolds([], [$nowhere, $this->request('slow.example', 1)]);

		$summary = $this->service->summary();

		$this->assertSame(2, $summary['failing'], 'it is still a failing delivery');
		$this->assertSame(['slow.example'], array_column($summary['instances'], 'host'));
	}
}
