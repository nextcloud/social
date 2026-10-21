<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Interaction;

use OCA\Social\Atproto\Reader\BlueskyInteractionSource;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ActionService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\Interaction\ActivityPubInteractionSource;
use OCA\Social\Service\Interaction\InteractionService;
use OCA\Social\Service\RemoteFetchQueue;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class InteractionServiceTest extends TestCase {
	private static function person(string $id): Person {
		return (new Person())->setId($id);
	}

	public function testOpeningReactionListReadsTheNetworkBeforeReturningAndKeepsLocalActorsFirst(): void {
		$post = (new Note())->setId('https://social.test/@alice/1');
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturn($post);
		$actions = $this->createMock(ActionService::class);
		$actions->method('reactedBy')->willReturn([self::person('https://social.test/@bob')]);
		$cacheActors = $this->createMock(CacheActorService::class);
		$cacheActors->method('getCachedFromIds')->willReturnCallback(static fn (array $ids): array => array_combine($ids, array_map(static fn (string $id): Person => self::person($id), $ids)));
		// a memcache only the background job's process sees, as APCu is: what
		// it listed must still reach the web request
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($this->createStub(ICache::class));
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->method('fillInteractions')->willReturn(false);
		$fediverse = $this->createMock(ActivityPubInteractionSource::class);
		$fediverse->method('supports')->willReturn(false);
		$bluesky = $this->createMock(BlueskyInteractionSource::class);
		$bluesky->method('supports')->willReturn(true);
		$bluesky->expects($this->exactly(2))->method('actors')
			->willReturnCallback(function (Note $seenPost, string $type, int $limit, ?int $timeout) use ($post): array {
				$this->assertSame($post, $seenPost);
				$this->assertContains($type, ['Like', 'Announce']);
				$this->assertSame(InteractionService::LIMIT, $limit);
				$this->assertSame(3, $timeout);

				return $type === 'Like'
					? ['https://bsky.app/profile/did:plc:carol', 'https://social.test/@bob']
					: [];
			});
		$bluesky->expects($this->once())->method('quotes')->with($post, InteractionService::LIMIT, 3)->willReturn(2);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[ActivityPubInteractionSource::class, $fediverse], [BlueskyInteractionSource::class, $bluesky]]);
		$service = new InteractionService($actions, $cacheActors, $streams, $durableCache, $queue, new NullLogger(), $container);

		$this->assertSame(
			['https://social.test/@bob', 'https://bsky.app/profile/did:plc:carol'],
			array_map(static fn (Person $p): string => $p->getId(), $service->reactedBy($post, 'Like', 10)['accounts']),
			'the visible request reads reactions before returning',
		);
		$this->assertFalse($service->askForQuotes($post), 'the quote list is read before its first response');

		$this->assertSame(
			['https://social.test/@bob', 'https://bsky.app/profile/did:plc:carol'],
			array_map(static fn (Person $p): string => $p->getId(), $service->reactedBy($post, 'Like', 10)['accounts']),
		);
		$this->assertSame(['https://social.test/@bob'], array_map(static fn (Person $p): string => $p->getId(), $service->reactedBy($post, 'Like', 1)['accounts']), 'within the limit');
		$this->assertSame([], array_map(static fn (Person $p): string => $p->getId(), array_slice($service->reactedBy($post, 'Announce', 10)['accounts'], 1)), 'each kind its own list');
	}

	public function testVisibleReactionsShareOneTimeoutBudgetAcrossFediverseAndBluesky(): void {
		$post = (new Note())->setId('https://social.test/@alice/dual-published');
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturn($post);
		$actions = $this->createMock(ActionService::class);
		$actions->method('reactedBy')->willReturn([]);
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->never())->method('fillInteractions');
		$fediverse = $this->createMock(ActivityPubInteractionSource::class);
		$fediverse->method('supports')->willReturn(true);
		$fediverse->expects($this->once())->method('actors')->willReturnCallback(
			static function (Note $post, string $type, int $limit, ?int $timeout): array {
				usleep(1_200_000);

				return [];
			},
		);
		$bluesky = $this->createMock(BlueskyInteractionSource::class);
		$bluesky->method('supports')->willReturn(true);
		$bluesky->expects($this->once())->method('actors')->willReturnCallback(
			function (Note $post, string $type, int $limit, ?int $timeout): array {
				$this->assertLessThan(3, $timeout, 'the second protocol receives only the remaining request budget');
				$this->assertGreaterThan(0, $timeout);

				return [];
			},
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[ActivityPubInteractionSource::class, $fediverse], [BlueskyInteractionSource::class, $bluesky]]);
		$service = new InteractionService(
			$actions,
			$this->createMock(CacheActorService::class),
			$streams,
			$durableCache,
			$queue,
			new NullLogger(),
			$container,
		);

		$this->assertFalse($service->reactedBy($post, 'Like', 10)['filling']);
	}

	public function testMissingListedActorsKeepTheAvatarListFillingUntilTheirProfilesAreCached(): void {
		$post = (new Note())->setId('https://remote.example/notes/1');
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturn($post);
		$actions = $this->createMock(ActionService::class);
		$cacheActors = $this->createMock(CacheActorService::class);
		$resolved = false;
		$actorId = 'https://remote.example/users/alice';
		$cacheActors->method('getCachedFromIds')->willReturnCallback(static function (array $ids) use (&$resolved, $actorId): array {
			return $resolved ? [$actorId => self::person($actorId)] : [];
		});
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->method('fillInteractions')->willReturn(false);
		$queue->expects($this->once())->method('resolveActors')->with([$actorId]);
		$fediverse = $this->createMock(ActivityPubInteractionSource::class);
		$fediverse->method('supports')->willReturn(true);
		$fediverse->method('actors')->with($post, 'Like', InteractionService::LIMIT)->willReturn([$actorId]);
		$bluesky = $this->createMock(BlueskyInteractionSource::class);
		$bluesky->method('supports')->willReturn(false);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[ActivityPubInteractionSource::class, $fediverse], [BlueskyInteractionSource::class, $bluesky]]);
		$service = new InteractionService($actions, $cacheActors, $streams, $durableCache, $queue, new NullLogger(), $container);

		$service->fill($post->getId(), 'Like');
		$waiting = $service->reactedBy($post, 'Like', 12);
		$this->assertSame([], $waiting['accounts']);
		$this->assertTrue($waiting['filling']);

		$resolved = true;
		$ready = $service->reactedBy($post, 'Like', 12);
		$this->assertSame([$actorId], array_map(static fn (Person $person): string => $person->getId(), $ready['accounts']));
		$this->assertFalse($ready['filling']);
	}

	public function testAConcurrentVisibleReadUsesTheInFlightListInsteadOfAskingAgain(): void {
		$post = (new Note())->setId('https://remote.example/notes/1');
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$actions = $this->createMock(ActionService::class);
		$actions->method('reactedBy')->willReturn([]);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->never())->method('fillInteractions');
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->once())->method('isLocked')->with(
			'social-visible-reactions-' . md5('Like' . "\0" . $post->getId()),
			ILockingProvider::LOCK_EXCLUSIVE,
		)->willReturn(true);
		$service = new InteractionService(
			$actions,
			$this->createMock(CacheActorService::class),
			$this->createMock(StreamRequest::class),
			$durableCache,
			$queue,
			new NullLogger(),
			$this->createMock(ContainerInterface::class),
			$locks,
		);

		$this->assertSame(['accounts' => [], 'filling' => true], $service->reactedBy($post, 'Like', 12));
	}

	public function testFailedVisibleReadWaitsForTheQueuedRefreshAndThenUsesItsResult(): void {
		$post = (new Note())->setId('https://remote.example/notes/1');
		$actorId = 'https://remote.example/users/alice';
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->with($post->getId())->willReturn($post);
		$actions = $this->createMock(ActionService::class);
		$actions->method('reactedBy')->willReturn([]);
		$cacheActors = $this->createMock(CacheActorService::class);
		$cacheActors->method('getCachedFromIds')->willReturnCallback(static fn (array $ids): array => array_combine($ids, array_map(static fn (string $id): Person => self::person($id), $ids)));
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->once())->method('fillInteractions')->with($post, 'Like')->willReturn(true);
		$bluesky = $this->createMock(BlueskyInteractionSource::class);
		$bluesky->method('supports')->willReturn(true);
		$bluesky->expects($this->exactly(2))->method('actors')->willReturnCallback(static function () use ($actorId): array {
			static $calls = 0;
			if (++$calls === 1) {
				throw new \RuntimeException('AppView unavailable');
			}

			return [$actorId];
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[ActivityPubInteractionSource::class, $this->createStub(ActivityPubInteractionSource::class)],
			[BlueskyInteractionSource::class, $bluesky],
		]);
		$service = new InteractionService($actions, $cacheActors, $streams, $durableCache, $queue, new NullLogger(), $container);

		$waiting = $service->reactedBy($post, 'Like', 12);
		$this->assertSame([], $waiting['accounts']);
		$this->assertTrue($waiting['filling']);
		$this->assertTrue($service->reactedBy($post, 'Like', 12)['filling'], 'polling does not repeat the failed AppView request');

		$service->fill($post->getId(), 'Like');
		$ready = $service->reactedBy($post, 'Like', 12);
		$this->assertSame([$actorId], array_map(static fn (Person $person): string => $person->getId(), $ready['accounts']));
		$this->assertFalse($ready['filling'], 'the background result clears the pending state');
	}

	public function testFailedBackgroundRefreshKeepsTheLastKnownReactionActors(): void {
		$post = (new Note())->setId('https://remote.example/notes/1');
		$oldActor = 'https://remote.example/users/alice';
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->with($post->getId())->willReturn($post);
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$key = md5('Like' . "\0" . $post->getId());
		$durableCache->setShared('social.reactors', $key, [$oldActor], 3600);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->never())->method('fillInteractions');
		$bluesky = $this->createMock(BlueskyInteractionSource::class);
		$bluesky->method('supports')->willReturn(true);
		$bluesky->expects($this->once())->method('actors')->willThrowException(new \RuntimeException('AppView unavailable'));
		$fediverse = $this->createMock(ActivityPubInteractionSource::class);
		$fediverse->method('supports')->willReturn(false);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[ActivityPubInteractionSource::class, $fediverse],
			[BlueskyInteractionSource::class, $bluesky],
		]);
		$service = new InteractionService(
			$this->createMock(ActionService::class),
			$this->createMock(CacheActorService::class),
			$streams,
			$durableCache,
			$queue,
			new NullLogger(),
			$container,
		);

		$service->fill($post->getId(), 'Like');

		$this->assertSame([$oldActor], $durableCache->getShared('social.reactors', $key));
	}
}
