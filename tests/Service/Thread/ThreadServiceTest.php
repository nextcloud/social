<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Thread;

use OCA\Social\Atproto\Reader\BlueskyThreadSource;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\Thread\ActivityPubThreadSource;
use OCA\Social\Service\Thread\ThreadService;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ThreadServiceTest extends TestCase {
	public function testBlueskyConversationCanBeReadInlineWhenOpened(): void {
		$post = (new Note())->setId('at://did:plc:alice/app.bsky.feed.post/1');
		$streams = $this->createStub(StreamRequest::class);
		$bluesky = $this->createMock(BlueskyThreadSource::class);
		$bluesky->expects($this->once())->method('supports')->with($post)->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(BlueskyThreadSource::class)->willReturn($bluesky);

		$viewer = (new Person())->setId('https://social.test/@alice');
		$bluesky->expects($this->once())->method('fillVisible')->with($post, ThreadService::BUDGET, $viewer)->willReturn(2);
		$bluesky->expects($this->once())->method('hasDeepRepliesPending')->willReturn(true);
		$service = new ThreadService($streams, new NullLogger(), $this->createStub(ILockingProvider::class), $container);
		$this->assertTrue($service->fillBluesky($post, $viewer));
		$this->assertTrue($service->hasPendingBlueskyReplies());
	}

	public function testContextPollsReuseARecentlyReadBlueskyThread(): void {
		$post = (new Note())->setId('at://did:plc:alice/app.bsky.feed.post/1');
		$bluesky = $this->createMock(BlueskyThreadSource::class);
		$bluesky->expects($this->exactly(2))->method('supports')->with($post)->willReturn(true);
		$bluesky->expects($this->once())->method('fillVisible')->with($post, ThreadService::BUDGET, null)->willReturn(0);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(BlueskyThreadSource::class)->willReturn($bluesky);
		$cache = $this->createMock(DurableCache::class);
		$cache->expects($this->exactly(3))->method('getShared')
			->with('social.thread.visible', md5($post->getId()))
			->willReturnOnConsecutiveCalls(null, null, 'done');
		$cache->expects($this->once())->method('setShared')
			->with('social.thread.visible', md5($post->getId()), 'done', 10);
		$service = new ThreadService($this->createStub(StreamRequest::class), new NullLogger(), $this->createStub(ILockingProvider::class), $container, $cache);

		$this->assertTrue($service->fillBluesky($post));
		// The frontend asks for the context again after the request that filled
		// it; the second request should use the just-read thread instead of
		// making another synchronous AppView call.
		$this->assertTrue($service->fillBluesky($post));
	}

	public function testFailedVisibleReadFallsBackWithoutRepeatingTheSynchronousRequest(): void {
		$post = (new Note())->setId('at://did:plc:alice/app.bsky.feed.post/1');
		$bluesky = $this->createMock(BlueskyThreadSource::class);
		$bluesky->expects($this->exactly(2))->method('supports')->with($post)->willReturn(true);
		$bluesky->expects($this->once())->method('fillVisible')->with($post, ThreadService::BUDGET, null)->willThrowException(new \RuntimeException('AppView unavailable'));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(BlueskyThreadSource::class)->willReturn($bluesky);
		$cache = $this->createMock(DurableCache::class);
		$cache->expects($this->exactly(3))->method('getShared')
			->with('social.thread.visible', md5($post->getId()))
			->willReturnOnConsecutiveCalls(null, null, 'failed');
		$cache->expects($this->once())->method('setShared')
			->with('social.thread.visible', md5($post->getId()), 'failed', 5);
		$service = new ThreadService($this->createStub(StreamRequest::class), new NullLogger(), $this->createStub(ILockingProvider::class), $container, $cache);

		$this->assertFalse($service->fillBluesky($post));
		// The caller queues its normal background retry after this failure. A
		// follow-up context poll should not keep blocking on the failed AppView.
		$this->assertFalse($service->fillBluesky($post));
	}

	public function testFailedBlueskyConversationReadIsLeftForTheBackgroundQueue(): void {
		$post = (new Note())->setId('at://did:plc:alice/app.bsky.feed.post/1');
		$streams = $this->createStub(StreamRequest::class);
		$bluesky = $this->createMock(BlueskyThreadSource::class);
		$bluesky->method('supports')->with($post)->willReturn(true);
		$bluesky->expects($this->once())->method('fillVisible')->with($post, ThreadService::BUDGET, null)->willThrowException(new \RuntimeException('AppView unavailable'));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(BlueskyThreadSource::class)->willReturn($bluesky);

		$this->assertFalse((new ThreadService($streams, new NullLogger(), $this->createStub(ILockingProvider::class), $container))->fillBluesky($post));
	}

	public function testConcurrentBlueskyConversationReadIsReportedAsPending(): void {
		$post = (new Note())->setId('at://did:plc:alice/app.bsky.feed.post/1');
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->once())->method('isLocked')->willReturn(true);
		$locks->expects($this->never())->method('acquireLock');
		$bluesky = $this->createMock(BlueskyThreadSource::class);
		$bluesky->expects($this->once())->method('supports')->with($post)->willReturn(true);
		$bluesky->expects($this->never())->method('fillVisible');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(BlueskyThreadSource::class)->willReturn($bluesky);

		$this->assertNull((new ThreadService($this->createStub(StreamRequest::class), new NullLogger(), $locks, $container))->fillBluesky($post));
	}

	public function testPartialFediverseReadIsRetriedInsteadOfCachedAsComplete(): void {
		$post = (new Note())->setId('https://remote.example/notes/1');
		$fediverse = $this->createMock(ActivityPubThreadSource::class);
		$fediverse->expects($this->exactly(2))->method('supports')->with($post)->willReturn(true);
		$fediverse->expects($this->once())->method('fillVisible')->with($post, ThreadService::BUDGET)->willReturn(1);
		$fediverse->expects($this->once())->method('hasReadFailures')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ActivityPubThreadSource::class)->willReturn($fediverse);
		$cache = $this->createMock(DurableCache::class);
		$key = 'activitypub:' . md5($post->getId());
		$cache->expects($this->exactly(3))->method('getShared')
			->with('social.thread.visible', $key)
			->willReturnOnConsecutiveCalls(null, null, 'failed');
		$cache->expects($this->once())->method('setShared')
			->with('social.thread.visible', $key, 'failed', 5);
		$service = new ThreadService($this->createStub(StreamRequest::class), new NullLogger(), $this->createStub(ILockingProvider::class), $container, $cache);

		$this->assertTrue($service->fillActivityPub($post));
		$this->assertFalse($service->fillActivityPub($post));
	}

	/** A post written here and published to Bluesky is asked of both. */
	public function testEveryNetworkThePostIsOnIsAskedWithWhatIsLeftOfTheBudget(): void {
		$post = (new Note())->setId('https://social.test/@alice/1');
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturn($post);
		$fediverse = $this->createMock(ActivityPubThreadSource::class);
		$fediverse->method('supports')->willReturn(true);
		$fediverse->expects($this->once())->method('fill')->with($post, ThreadService::BUDGET)->willReturn(100);
		$bluesky = $this->createMock(BlueskyThreadSource::class);
		$bluesky->method('supports')->willReturn(true);
		$bluesky->expects($this->once())->method('fill')->with($post, ThreadService::BUDGET - 100)->willReturn(7);
		$bluesky->expects($this->once())->method('hasDeepRepliesPending')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[ActivityPubThreadSource::class, $fediverse], [BlueskyThreadSource::class, $bluesky]]);

		$service = new ThreadService($streams, new NullLogger(), $this->createStub(ILockingProvider::class), $container);
		$this->assertSame(107, $service->fill($post->getId()));
		$this->assertTrue($service->hasPendingBlueskyReplies());
	}
}
