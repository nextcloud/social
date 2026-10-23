<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Discovery;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\DirectorySource;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\Discovery\FediversePostSource;
use OCA\Social\Service\Discovery\PostSourceStats;
use OCA\Social\Service\Discovery\RemotePostDiscoveryBudget;
use OCA\Social\Service\FediverseDirectoryService;
use OCA\Social\Service\HostBreaker;
use OCA\Social\Service\StreamQueueService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class FediversePostSourceTest extends TestCase {
	private function budget(bool $allowed = true): RemotePostDiscoveryBudget {
		$budget = $this->createStub(RemotePostDiscoveryBudget::class);
		$budget->method('reserve')->willReturn($allowed);

		return $budget;
	}

	public function testThePeersHashtagTimelinesAreReadAndEachPostFetchedFromItsOwnServer(): void {
		$directory = $this->createMock(FediverseDirectoryService::class);
		$directory->method('sources')->willReturn([
			new DirectorySource('social.test', DirectorySource::KIND_LOCAL, 'social.test'),
			new DirectorySource('mastodon.example', DirectorySource::KIND_MASTODON, 'Mastodon'),
			new DirectorySource('lemmy.example', DirectorySource::KIND_LEMMY, 'Lemmy'),
			new DirectorySource('down.example', DirectorySource::KIND_MASTODON, 'Down'),
		]);
		$asked = [];
		$curl = $this->createMock(CurlService::class);
		$curl->method('retrieveJsonMany')->willReturnCallback(
			static function (array $urls, array $options, array $perUrl, ?array &$statuses, ?array &$failed, ?array &$retryAfters) use (&$asked): array {
				$asked = $urls;
				$down = 'https://down.example/api/v1/timelines/tag/nextcloud?limit=20';
				$good = 'https://mastodon.example/api/v1/timelines/tag/nextcloud?limit=20';
				$statuses = [$good => 200];
				$failed = [$down];
				$retryAfters = [];

				return [$good => [['uri' => 'https://a.example/notes/1'], ['uri' => 'https://held.example/notes/2'], ['uri' => 'nope'], ['uri' => 'https://b.example/notes/3']], $down => null];
			}
		);
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturnCallback(static fn (string $id): Stream => $id === 'https://held.example/notes/2' ? new Note() : throw new StreamNotFoundException());
		$fetched = [];
		$queue = $this->createMock(StreamQueueService::class);
		$timeouts = [];
		$queue->method('fetchNow')->willReturnCallback(static function (string $uri, ?int $timeout) use (&$fetched, &$timeouts): void {
			$fetched[] = $uri;
			$timeouts[] = $timeout;
		});
		$source = new FediversePostSource($directory, $curl, $streams, $queue, new NullLogger(), $this->createMock(HostBreaker::class), $this->budget());

		$stats = $source->tagged('nextcloud', 10, null);
		$this->assertSame(2, $stats->postsStored);
		$this->assertSame(4, $stats->requests, 'Remote post hydration is included in the request count');
		$this->assertSame(1, $stats->failedRequests, 'The unavailable directory peer is included in failures');
		$this->assertSame(['https://mastodon.example/api/v1/timelines/tag/nextcloud?limit=20', 'https://down.example/api/v1/timelines/tag/nextcloud?limit=20'], $asked, 'Mastodon-speaking peers only, not this server');
		$this->assertSame(['https://a.example/notes/1', 'https://b.example/notes/3'], $fetched);
		$this->assertSame([2, 2], $timeouts, 'Origin fetches have a short, explicit timeout');
		$this->assertSame(0, $source->matching('anything', 10, null)->requests, 'the fediverse has no public search of posts');
	}

	public function testOnlyPostsWrittenSinceTheBoundAreFetched(): void {
		$directory = $this->createMock(FediverseDirectoryService::class);
		$directory->method('sources')->willReturn([new DirectorySource('mastodon.example', DirectorySource::KIND_MASTODON, 'Mastodon')]);
		$curl = $this->createMock(CurlService::class);
		$curl->method('retrieveJsonMany')->willReturnCallback(
			static function (array $urls, array $options, array $perUrl, ?array &$statuses, ?array &$failed, ?array &$retryAfters): array {
				$url = 'https://mastodon.example/api/v1/timelines/tag/nextcloud?limit=20';
				$statuses = [$url => 200];
				$failed = [];
				$retryAfters = [];

				return [$url => [
					['uri' => 'https://a.example/notes/new', 'created_at' => '2026-10-09T12:00:00.000Z'],
					['uri' => 'https://a.example/notes/old', 'created_at' => '2026-10-01T12:00:00.000Z'],
					['uri' => 'https://a.example/notes/undated'],
				]];
			}
		);
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$fetched = [];
		$queue = $this->createMock(StreamQueueService::class);
		$queue->method('fetchNow')->willReturnCallback(static function (string $uri, ?int $timeout) use (&$fetched): void {
			$fetched[] = $uri;
			self::assertSame(2, $timeout);
		});
		$source = new FediversePostSource($directory, $curl, $streams, $queue, new NullLogger(), $this->createMock(HostBreaker::class), $this->budget());

		$stats = $source->tagged('nextcloud', 20, null, (int)strtotime('2026-10-08T00:00:00Z'));
		$this->assertSame(1, $stats->postsStored);
		$this->assertSame(2, $stats->requests);
		$this->assertSame(['https://a.example/notes/new'], $fetched);
	}

	public function testRateLimitedPeerIsOpenedForItsRetryAfterInterval(): void {
		$directory = $this->createMock(FediverseDirectoryService::class);
		$directory->method('sources')->willReturn([
			new DirectorySource('limited.example', DirectorySource::KIND_MASTODON, 'Limited'),
			new DirectorySource('healthy.example', DirectorySource::KIND_MASTODON, 'Healthy'),
		]);
		$curl = $this->createMock(CurlService::class);
		$curl->method('retrieveJsonMany')->willReturnCallback(
			static function (array $urls, array $options, array $perUrl, ?array &$statuses, ?array &$failed, ?array &$retryAfters): array {
				$limited = 'https://limited.example/api/v1/timelines/tag/nextcloud?limit=20';
				$healthy = 'https://healthy.example/api/v1/timelines/tag/nextcloud?limit=20';
				$statuses = [$limited => 429, $healthy => 200];
				$failed = [$limited];
				$retryAfters = [$limited => 240];

				return [$limited => null, $healthy => [['uri' => 'https://post.example/notes/1']]];
			}
		);
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$queue = $this->createMock(StreamQueueService::class);
		$breaker = $this->createMock(HostBreaker::class);
		$breaker->method('openUntil')->willReturn(0);
		$breaker->expects($this->once())->method('open')->with('limited.example', 240);
		$breaker->expects($this->once())->method('close')->with('healthy.example');
		$source = new FediversePostSource($directory, $curl, $streams, $queue, new NullLogger(), $breaker, $this->budget());

		$stats = $source->tagged('nextcloud', 10, null);
		$this->assertSame(1, $stats->postsStored);
		$this->assertSame(3, $stats->requests, 'The two timeline requests and one origin fetch are counted');
		$this->assertSame(1, $stats->failedRequests);
		$this->assertSame(1, $stats->rateLimitedRequests);
	}

	public function testOpenPeersAreSkippedWithoutMakingARequest(): void {
		$directory = $this->createMock(FediverseDirectoryService::class);
		$directory->method('sources')->willReturn([new DirectorySource('unavailable.example', DirectorySource::KIND_MASTODON, 'Unavailable')]);
		$curl = $this->createMock(CurlService::class);
		$curl->expects($this->never())->method('retrieveJsonMany');
		$breaker = $this->createMock(HostBreaker::class);
		$breaker->expects($this->once())->method('openUntil')->with('unavailable.example')->willReturn(2_000_000_100);
		$source = new FediversePostSource(
			$directory,
			$curl,
			$this->createMock(StreamRequest::class),
			$this->createMock(StreamQueueService::class),
			new NullLogger(),
			$breaker,
			$this->budget(),
		);

		$this->assertEquals(PostSourceStats::empty(), $source->tagged('nextcloud', 10, null));
	}

	public function testExhaustedSharedBudgetSkipsProviderRequests(): void {
		$directory = $this->createMock(FediverseDirectoryService::class);
		$directory->method('sources')->willReturn([new DirectorySource('mastodon.example', DirectorySource::KIND_MASTODON, 'Mastodon')]);
		$curl = $this->createMock(CurlService::class);
		$curl->expects($this->never())->method('retrieveJsonMany');
		$source = new FediversePostSource(
			$directory,
			$curl,
			$this->createMock(StreamRequest::class),
			$this->createMock(StreamQueueService::class),
			new NullLogger(),
			$this->createMock(HostBreaker::class),
			$this->budget(false),
		);

		$this->assertEquals(PostSourceStats::empty(), $source->tagged('nextcloud', 10, null));
	}

	public function testPartialSharedBudgetOnlyAsksTheAuthorizedPeer(): void {
		$directory = $this->createMock(FediverseDirectoryService::class);
		$directory->method('sources')->willReturn([
			new DirectorySource('first.example', DirectorySource::KIND_MASTODON, 'First'),
			new DirectorySource('second.example', DirectorySource::KIND_MASTODON, 'Second'),
		]);
		$curl = $this->createMock(CurlService::class);
		$curl->expects($this->once())->method('retrieveJsonMany')->with(
			['https://first.example/api/v1/timelines/tag/nextcloud?limit=20'],
			$this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything()
		)->willReturnCallback(static function (array $urls, array $options, array $perUrl, ?array &$statuses, ?array &$failed, ?array &$retryAfters): array {
			$statuses = [$urls[0] => 200];
			$failed = [];
			$retryAfters = [];

			return [$urls[0] => []];
		});
		$budget = $this->createMock(RemotePostDiscoveryBudget::class);
		$budget->expects($this->exactly(2))->method('reserve')->willReturnOnConsecutiveCalls(true, false);
		$source = new FediversePostSource(
			$directory,
			$curl,
			$this->createMock(StreamRequest::class),
			$this->createMock(StreamQueueService::class),
			new NullLogger(),
			$this->createMock(HostBreaker::class),
			$budget,
		);

		$this->assertSame(1, $source->tagged('nextcloud', 10, null)->requests);
	}
}
