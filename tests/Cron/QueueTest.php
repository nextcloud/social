<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\Queue;
use OCA\Social\Exceptions\SignatureException;
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
use Psr\Log\LoggerInterface;

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
	/** @var LoggerInterface&MockObject */
	private $logger;
	private Queue $job;

	protected function setUp(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->requestQueueService = $this->createMock(RequestQueueService::class);
		$this->streamQueueService = $this->createMock(StreamQueueService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new Queue(
			$time,
			$this->requestQueueService,
			$this->streamQueueService,
			$this->activityService,
			$this->logger
		);
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

	/**
	 * The catch used to name SocialAppConfigException and nothing else, but
	 * manageRequest() also lets a SignatureException out (openssl_sign on an
	 * empty or corrupt private key) and an \OCP\DB\Exception escape the calls
	 * that end the row. The row is already `running` by then, so it was neither
	 * retried nor counted against MAX_TRIES until the stale reaper freed it an
	 * hour later — and every row left in the 200-row batch was skipped.
	 */
	public function testARowThatFailsInAnUnexpectedWayCostsOnlyThatRow(): void {
		$bad = (new RequestQueue())->setToken('bad');
		$good = (new RequestQueue())->setToken('good');
		$this->requestQueueService->method('getRequestStandby')->willReturn([$bad, $good]);
		$this->streamQueueService->method('getRequestStandby')->willReturn([]);
		$sent = [];
		$this->activityService->method('manageRequest')
			->willReturnCallback(function (RequestQueue $request) use (&$sent): void {
				if ($request->getToken() === 'bad') {
					throw new SignatureException('cannot sign: the private key is empty');
				}
				$sent[] = $request->getToken();
			});

		// and the row goes back to standby, so it is retried and eventually
		// exhausts its tries instead of sitting `running` forever
		$ended = [];
		$this->requestQueueService->expects($this->once())->method('endRequest')
			->willReturnCallback(function (RequestQueue $request, bool $success) use (&$ended): void {
				$ended[] = [$request->getToken(), $success];
			});

		$logged = [];
		$this->logger->expects($this->once())->method('warning')
			->willReturnCallback(function (string $message) use (&$logged): void {
				$logged[] = $message;
			});

		$this->job->start($this->jobList);

		$this->assertSame(['good'], $sent, 'the rest of the batch is still delivered');
		$this->assertSame([['bad', false]], $ended);
		$this->assertStringContainsString('bad', $logged[0]);
		$this->assertStringContainsString('SignatureException', $logged[0]);
	}

	public function testAFailureToReleaseARowIsLoggedAndDoesNotStopTheQueue(): void {
		$bad = (new RequestQueue())->setToken('bad');
		$good = (new RequestQueue())->setToken('good');
		$this->requestQueueService->method('getRequestStandby')->willReturn([$bad, $good]);
		$this->streamQueueService->method('getRequestStandby')->willReturn([]);
		$sent = [];
		$this->activityService->method('manageRequest')
			->willReturnCallback(function (RequestQueue $request) use (&$sent): void {
				if ($request->getToken() === 'bad') {
					throw new \RuntimeException('boom');
				}
				$sent[] = $request->getToken();
			});
		$this->requestQueueService->method('endRequest')
			->willThrowException(new \RuntimeException('the database is gone'));

		$this->logger->expects($this->exactly(2))->method('warning');

		$this->job->start($this->jobList);

		$this->assertSame(['good'], $sent);
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

		// the catch used to be empty: a misconfigured app dropped every
		// delivery without a line anywhere
		$logged = [];
		$this->logger->expects($this->once())->method('warning')
			->willReturnCallback(function (string $message, array $context = []) use (&$logged): void {
				$logged[] = $message;
			});

		$this->job->start($this->jobList);

		$this->assertSame(['good'], $sent);
		$this->assertCount(1, $logged);
		$this->assertStringContainsString('bad', $logged[0]);
		$this->assertStringContainsString('not configured', $logged[0]);
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
