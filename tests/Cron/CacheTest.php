<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\Cache;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CacheTest extends TestCase {
	private const NOW = 1700000000;

	/** @var AccountService&MockObject */
	private $accountService;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var DocumentService&MockObject */
	private $documentService;
	/** @var HashtagService&MockObject */
	private $hashtagService;
	/** @var StreamService&MockObject */
	private $streamService;
	/** @var CacheActorsRequest&MockObject */
	private $cacheActorsRequest;
	/** @var IJobList&MockObject */
	private $jobList;
	/** @var LoggerInterface&MockObject */
	private $logger;
	private Cache $job;

	protected function setUp(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->accountService = $this->createMock(AccountService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->documentService = $this->createMock(DocumentService::class);
		$this->hashtagService = $this->createMock(HashtagService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new Cache(
			$time,
			$this->accountService,
			$this->cacheActorService,
			$this->documentService,
			$this->hashtagService,
			$this->streamService,
			$this->cacheActorsRequest,
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

	public function testRunRefreshesEveryCacheAndSyncsRemoteTimelines(): void {
		$this->accountService->expects($this->once())->method('manageDeletedActors');
		$this->accountService->expects($this->once())->method('manageCacheLocalActors');
		$this->cacheActorService->expects($this->once())->method('manageCacheRemoteActors');
		$this->cacheActorService->expects($this->once())->method('manageDetailsRemoteActors');
		$this->documentService->expects($this->once())->method('manageCacheDocuments');
		$this->hashtagService->expects($this->once())->method('manageHashtags');
		$bob = $this->createMock(Person::class);
		$carol = $this->createMock(Person::class);
		$this->cacheActorsRequest->expects($this->once())->method('getRemoteActorsToUpdate')->with(false)->willReturn([$bob, $carol]);
		$synced = [];
		$this->streamService->expects($this->exactly(2))->method('syncRemoteTimeline')
			->willReturnCallback(function (Person $actor) use (&$synced): int {
				$synced[] = $actor;

				return 0;
			});
		$this->jobList->expects($this->once())->method('setLastRun')->with($this->job);

		$this->job->start($this->jobList);

		$this->assertSame([$bob, $carol], $synced);
	}

	public function testAFailingStepDoesNotStopTheOthers(): void {
		// An Error (e.g. the undefined-constant fatal manageDeletedActors used to
		// raise) is not an Exception, so a catch (Exception) would have let it kill
		// the whole run; the cron catches Throwable.
		$this->accountService->method('manageDeletedActors')->willThrowException(new \Error('undefined constant'));
		$this->cacheActorService->method('manageCacheRemoteActors')->willThrowException(new \RuntimeException('network'));
		$this->accountService->expects($this->once())->method('manageCacheLocalActors');
		$this->cacheActorService->expects($this->once())->method('manageDetailsRemoteActors');
		$this->documentService->expects($this->once())->method('manageCacheDocuments');
		$this->hashtagService->expects($this->once())->method('manageHashtags');
		$this->cacheActorsRequest->expects($this->once())->method('getRemoteActorsToUpdate')->willReturn([]);

		$this->job->start($this->jobList);
	}

	public function testOneUnreachableRemoteActorDoesNotStopTheTimelineSync(): void {
		$gone = $this->createMock(Person::class);
		$alive = $this->createMock(Person::class);
		$this->cacheActorsRequest->method('getRemoteActorsToUpdate')->willReturn([$gone, $alive]);
		$this->streamService->expects($this->exactly(2))->method('syncRemoteTimeline')
			->willReturnCallback(function (Person $actor) use ($gone): int {
				if ($actor === $gone) {
					throw new \RuntimeException('410 Gone');
				}

				return 3;
			});

		$this->job->start($this->jobList);
	}

	public function testRunIsSkippedWhenTheLastRunIsTooRecent(): void {
		$this->job->setLastRun(self::NOW - 60);
		$this->accountService->expects($this->never())->method('manageDeletedActors');
		$this->jobList->expects($this->never())->method('setLastRun');

		$this->job->start($this->jobList);
	}
}
