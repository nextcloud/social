<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\FillThread;
use OCA\Social\Service\RemoteFetchQueue;
use OCA\Social\Service\Thread\ThreadService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class FillThreadTest extends TestCase {
	public function testTheWorkerClearsThePendingMarkerAfterTheThreadRead(): void {
		$threads = $this->createMock(ThreadService::class);
		$threads->expects($this->once())->method('fill')->with('https://remote.example/notes/1')->willReturn(3);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->once())->method('completeThreadFill')->with('https://remote.example/notes/1');
		$job = new FillThread($this->createStub(ITimeFactory::class), $threads, $queue);

		(new \ReflectionMethod(FillThread::class, 'run'))->invoke($job, ['post' => 'https://remote.example/notes/1']);
	}

	public function testTheWorkerAllowsAnotherPassWhenBlueskyHasSavedContinuations(): void {
		$threads = $this->createMock(ThreadService::class);
		$threads->expects($this->once())->method('fill')->with('at://did:plc:alice/app.bsky.feed.post/1')->willReturn(20);
		$threads->expects($this->once())->method('hasPendingBlueskyReplies')->willReturn(true);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->once())->method('retryThreadFill')->with('at://did:plc:alice/app.bsky.feed.post/1');
		$queue->expects($this->never())->method('completeThreadFill');
		$job = new FillThread($this->createStub(ITimeFactory::class), $threads, $queue);

		(new \ReflectionMethod(FillThread::class, 'run'))->invoke($job, ['post' => 'at://did:plc:alice/app.bsky.feed.post/1']);
	}

	public function testTheWorkerClearsThePendingMarkerWhenTheThreadReadThrows(): void {
		$threads = $this->createMock(ThreadService::class);
		$threads->expects($this->once())->method('fill')->willThrowException(new \RuntimeException('remote unavailable'));
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->once())->method('completeThreadFill')->with('https://remote.example/notes/1');
		$job = new FillThread($this->createStub(ITimeFactory::class), $threads, $queue);

		$this->expectException(\RuntimeException::class);
		(new \ReflectionMethod(FillThread::class, 'run'))->invoke($job, ['post' => 'https://remote.example/notes/1']);
	}

	public function testAnInvalidJobDoesNotClearAnyThreadMarker(): void {
		$threads = $this->createMock(ThreadService::class);
		$threads->expects($this->never())->method('fill');
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->never())->method('completeThreadFill');
		$job = new FillThread($this->createStub(ITimeFactory::class), $threads, $queue);

		(new \ReflectionMethod(FillThread::class, 'run'))->invoke($job, null);
	}
}
