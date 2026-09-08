<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\Queue;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Model\StreamQueue;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\StreamQueueService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class QueueTest extends TestCase {
	private const NOW = 1700000000;

	/** @var RequestQueueService&MockObject */
	private $requestQueueService;
	/** @var StreamQueueService&MockObject */
	private $streamQueueService;
	/** @var ActivityService&MockObject */
	private $activityService;
	/** @var IJobList&MockObject */
	private $jobList;
	private Queue $job;

	protected function setUp(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->requestQueueService = $this->createMock(RequestQueueService::class);
		$this->streamQueueService = $this->createMock(StreamQueueService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->jobList = $this->createMock(IJobList::class);

		$this->job = new Queue($time, $this->requestQueueService, $this->streamQueueService, $this->activityService);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testIsATimedJobRunningEveryTwelveMinutes(): void {
		$this->assertInstanceOf(TimedJob::class, $this->job);

		$interval = new \ReflectionProperty(TimedJob::class, 'interval');

		$this->assertSame(12 * 60, $interval->getValue($this->job));
	}

	public function testStandbyRequestsAreSentWithTheServiceTimeout(): void {
		$first = (new RequestQueue())->setToken('t1');
		$second = (new RequestQueue())->setToken('t2');
		$this->requestQueueService->method('getRequestStandby')->willReturn([$first, $second]);
		$this->streamQueueService->method('getRequestStandby')->willReturn([]);
		$this->activityService->expects($this->once())->method('manageInit');
		$managed = [];
		$this->activityService->expects($this->exactly(2))->method('manageRequest')
			->willReturnCallback(function (RequestQueue $request) use (&$managed): void {
				$this->assertSame(ActivityService::TIMEOUT_SERVICE, $request->getTimeout());
				$managed[] = $request->getToken();
			});

		$this->job->start($this->jobList);

		$this->assertSame(['t1', 't2'], $managed);
	}

	public function testAMisconfiguredRequestDoesNotStopTheQueue(): void {
		$bad = (new RequestQueue())->setToken('bad');
		$good = (new RequestQueue())->setToken('good');
		$this->requestQueueService->method('getRequestStandby')->willReturn([$bad, $good]);
		$this->streamQueueService->method('getRequestStandby')->willReturn([]);
		$sent = [];
		$this->activityService->expects($this->exactly(2))->method('manageRequest')
			->willReturnCallback(function (RequestQueue $request) use (&$sent): void {
				if ($request->getToken() === 'bad') {
					throw new SocialAppConfigException();
				}
				$sent[] = $request->getToken();
			});

		$this->job->start($this->jobList);

		$this->assertSame(['good'], $sent);
	}

	public function testStandbyStreamItemsAreProcessedAfterTheRequests(): void {
		$this->requestQueueService->method('getRequestStandby')->willReturn([]);
		$items = [$this->createMock(StreamQueue::class), $this->createMock(StreamQueue::class)];
		$this->streamQueueService->method('getRequestStandby')->willReturn($items);
		$processed = [];
		$this->streamQueueService->expects($this->exactly(2))->method('manageStreamQueue')
			->willReturnCallback(function (StreamQueue $item) use (&$processed): void {
				$processed[] = $item;
			});

		$this->job->start($this->jobList);

		$this->assertSame($items, $processed);
	}

	public function testRunIsSkippedWhenTheLastRunIsTooRecent(): void {
		$this->job->setLastRun(self::NOW - 60);
		$this->requestQueueService->expects($this->never())->method('getRequestStandby');
		$this->streamQueueService->expects($this->never())->method('getRequestStandby');

		$this->job->start($this->jobList);
	}
}
