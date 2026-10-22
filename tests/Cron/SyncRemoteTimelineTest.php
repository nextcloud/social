<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\SyncRemoteTimeline;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\RemoteFetchQueue;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The background half of opening a remote profile. */
class SyncRemoteTimelineTest extends TestCase {
	private CacheActorService|MockObject $cacheActorService;
	private StreamService|MockObject $streamService;
	private RemoteFetchQueue|MockObject $remoteFetchQueue;
	private SyncRemoteTimeline $job;

	protected function setUp(): void {
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->remoteFetchQueue = $this->createMock(RemoteFetchQueue::class);
		$this->job = new SyncRemoteTimeline(
			$this->createStub(ITimeFactory::class),
			$this->cacheActorService,
			$this->streamService,
			$this->remoteFetchQueue,
			new NullLogger()
		);
	}

	private function runJob($argument): void {
		(new \ReflectionMethod(SyncRemoteTimeline::class, 'run'))->invoke($this->job, $argument);
	}

	public function testTheActorsOutboxIsSynced(): void {
		$bob = new Person();
		$this->cacheActorService->expects($this->once())->method('getFromId')
			->with('https://remote.example/users/bob')->willReturn($bob);
		$this->streamService->expects($this->once())->method('syncRemoteTimelinePage')
			->with($bob, '', 0)
			->willReturn(['synced' => 1, 'next' => ['url' => 'https://remote.example/outbox?page=2', 'offset' => 0], 'success' => true]);
		$this->remoteFetchQueue->expects($this->once())->method('finishTimelinePage')
			->with('https://remote.example/users/bob', true, ['url' => 'https://remote.example/outbox?page=2', 'offset' => 0], true);

		$this->runJob(['actor' => 'https://remote.example/users/bob']);
	}

	public function testAnActorThatCannotBeResolvedEndsTheJobQuietly(): void {
		$this->cacheActorService->expects($this->once())->method('getFromId')->willThrowException(new RequestNetworkException());
		$this->streamService->expects($this->never())->method('syncRemoteTimelinePage');
		$this->remoteFetchQueue->expects($this->once())->method('finishTimelinePage')
			->with('https://gone.example/users/carol', false, null);

		$this->runJob(['actor' => 'https://gone.example/users/carol']);
	}

	public function testAJobWithNoActorDoesNothing(): void {
		$this->cacheActorService->expects($this->never())->method('getFromId');
		$this->streamService->expects($this->never())->method('syncRemoteTimelinePage');
		$this->remoteFetchQueue->expects($this->never())->method('finishTimelinePage');

		$this->runJob(null);
		$this->runJob(['actor' => '']);
	}

	public function testAnOutboxRefreshKeepsTheStoredPageCursor(): void {
		$id = 'https://remote.example/users/bob';
		$bob = new Person();
		$this->cacheActorService->method('getFromId')->with($id)->willReturn($bob);
		$this->streamService->expects($this->once())->method('syncRemoteTimelinePage')
			->with($bob, '', 0)
			->willReturn(['synced' => 1, 'next' => null, 'success' => true]);
		$this->remoteFetchQueue->expects($this->once())->method('finishTimelinePage')
			->with($id, true, null, false);

		$this->runJob(['actor' => $id, 'refresh_only' => '1']);
	}

	public function testAContinuationJobResumesAtTheSavedPageCursor(): void {
		$id = 'https://remote.example/users/bob';
		$url = 'https://remote.example/outbox?page=2';
		$bob = new Person();
		$this->cacheActorService->method('getFromId')->with($id)->willReturn($bob);
		$this->remoteFetchQueue->expects($this->once())->method('timelineCursor')->with($id)
			->willReturn(['url' => $url, 'offset' => 20]);
		$this->streamService->expects($this->once())->method('syncRemoteTimelinePage')
			->with($bob, $url, 20)
			->willReturn(['synced' => 1, 'next' => null, 'success' => true]);
		$this->remoteFetchQueue->expects($this->once())->method('finishTimelinePage')
			->with($id, true, null, true);

		$this->runJob(['actor' => $id, 'continuation' => '1']);
	}
}
