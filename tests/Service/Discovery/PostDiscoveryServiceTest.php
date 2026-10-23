<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Discovery;

use OCA\Social\Atproto\Reader\BlueskyPostSource;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\Discovery\FediversePostSource;
use OCA\Social\Service\Discovery\PostDiscoveryService;
use OCA\Social\Service\Discovery\PostSourceStats;
use OCA\Social\Service\RecommendationDiscoveryHealthService;
use OCA\Social\Service\RecommendationPreferenceService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class PostDiscoveryServiceTest extends TestCase {
	private static function stats(int $stored): PostSourceStats {
		return new PostSourceStats(1, 0, 0, $stored, $stored, 12);
	}

	public function testEveryNetworkIsAskedAsThePersonSearching(): void {
		$alice = (new Person())->setId('https://social.test/@alice');
		$cacheActors = $this->createMock(CacheActorService::class);
		$cacheActors->method('getFromId')->with($alice->getId())->willReturn($alice);
		$fediverse = $this->createMock(FediversePostSource::class);
		$fediverse->expects($this->once())->method('tagged')->with('nextcloud', PostDiscoveryService::LIMIT, null)->willReturn(self::stats(3));
		$fediverse->expects($this->once())->method('matching')->with('open source', PostDiscoveryService::LIMIT, $alice)->willReturn(self::stats(0));
		$bluesky = $this->createMock(BlueskyPostSource::class);
		$bluesky->method('tagged')->willReturn(self::stats(4));
		$bluesky->expects($this->once())->method('matching')->with('open source', PostDiscoveryService::LIMIT, $alice)->willReturn(self::stats(2));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[FediversePostSource::class, $fediverse], [BlueskyPostSource::class, $bluesky]]);
		$health = $this->createMock(RecommendationDiscoveryHealthService::class);
		$recorded = [];
		$health->expects($this->exactly(4))->method('record')->willReturnCallback(
			static function (string $provider, PostSourceStats $stats) use (&$recorded): void {
				$recorded[] = [$provider, $stats->postsStored];
			}
		);
		$service = new PostDiscoveryService($cacheActors, new NullLogger(), $health, $this->createStub(RecommendationPreferenceService::class), $container);

		$this->assertSame(7, $service->fill(PostDiscoveryService::TAG, 'nextcloud'));
		$this->assertSame(2, $service->fill(PostDiscoveryService::SEARCH, 'open source', $alice->getId()));
		$this->assertSame([['activitypub', 3], ['atproto', 4], ['activitypub', 0], ['atproto', 2]], $recorded);
	}

	public function testAFollowedTagIsAskedOfEveryNetworkAsNobodyFromWhenItWasLastRead(): void {
		$fediverse = $this->createMock(FediversePostSource::class);
		$fediverse->expects($this->once())->method('tagged')->with('nextcloud', 20, null, 1_700_000_000)->willReturn(new PostSourceStats(1, 0, 0, 2, 2, 12));
		$bluesky = $this->createMock(BlueskyPostSource::class);
		$bluesky->expects($this->once())->method('tagged')->with('nextcloud', 20, null, 1_700_000_000)->willThrowException(new \RuntimeException('down'));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[FediversePostSource::class, $fediverse], [BlueskyPostSource::class, $bluesky]]);
		$health = $this->createMock(RecommendationDiscoveryHealthService::class);
		$health->expects($this->exactly(2))->method('record');
		$service = new PostDiscoveryService($this->createMock(CacheActorService::class), new NullLogger(), $health, $this->createStub(RecommendationPreferenceService::class), $container);

		$this->assertSame(2, $service->fillTag('nextcloud', 20, 1_700_000_000), 'one network failing leaves the other');
	}

	public function testAdminPauseBlocksEveryPostDiscoveryEntryPoint(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$health = $this->createMock(RecommendationDiscoveryHealthService::class);
		$health->expects($this->never())->method('record');
		$preferences = $this->createStub(RecommendationPreferenceService::class);
		$preferences->method('remoteDiscoveryPaused')->willReturn(true);
		$service = new PostDiscoveryService(
			$this->createMock(CacheActorService::class),
			new NullLogger(),
			$health,
			$preferences,
			$container,
		);

		$this->assertSame(0, $service->fill(PostDiscoveryService::TAG, 'nextcloud'));
		$this->assertSame(0, $service->fill(PostDiscoveryService::SEARCH, 'open source'));
		$this->assertSame(0, $service->fillTag('nextcloud', 20, 1_700_000_000));
	}
}
