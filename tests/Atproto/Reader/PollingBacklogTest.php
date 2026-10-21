<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Watch;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\FeedPoller;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Atproto\Reader\NotificationPoller;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class PollingBacklogTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const AUTHOR = 'did:plc:z72i7hdynmk6r22z27h6tvur';
	private const NOW = 1791453600;
	private AppViewClient $appView;
	private string $checkpoint;
	private array $stored = [];
	private array $scheduled = [];
	private int $failures = 0;

	protected function setUp(): void {
		$this->checkpoint = self::stamp(100);
	}

	public static function readers(): iterable {
		yield 'author feed' => [false];
		yield 'notifications' => [true];
	}

	#[DataProvider('readers')]
	public function testBacklogsResumeAcrossBoundedRunsAndEmptyPagesWithoutSkippingEvents(bool $notifications): void {
		$poller = $this->poller($notifications);
		$pages = [
			'' => $this->page($notifications, range(225, 176), 'second'),
			'second' => $this->page($notifications, [], 'third'),
			'third' => $this->page($notifications, range(175, 126), 'last'),
			'last' => $this->page($notifications, range(125, 99), 'older'),
		];
		$asked = [];
		$this->appView->method($notifications ? 'queryAs' : 'query')->willReturnCallback(function (...$args) use ($notifications, &$pages, &$asked): array {
			$params = $args[$notifications ? 3 : 1];
			$this->assertSame(50, $params['limit']);
			$cursor = $params['cursor'] ?? '';
			$asked[] = $cursor;

			return $pages[$cursor];
		});

		foreach ([50, 0, 50, 26] as $count) {
			$result = $poller->poll(1);
			$this->assertSame(1, $result['requests']);
			$this->assertSame($count, $result[$notifications ? 'handled' : 'stored']);
		}
		$this->assertSame(['', 'second', 'third', 'last'], $asked);
		$this->assertSame(array_map(self::uri(...), range(225, 100)), $this->stored);
		$this->assertSame(self::stamp(225), $this->checkpoint);
		$this->assertSame([120, 120, 120, 120], $this->scheduled);

		$pages[''] = $this->page($notifications, [226, 225], 'older');
		$poller->poll(1);
		$this->assertSame(self::uri(226), $this->stored[126]);
		$pages['older'] = $this->page($notifications, [99], '');
		$poller->poll(1);
		$this->assertSame(self::stamp(226), $this->checkpoint);
	}

	#[DataProvider('readers')]
	public function testAFailedContinuationRetriesTheSamePageBeforeCommittingTheWatermark(bool $notifications): void {
		$poller = $this->poller($notifications);
		$calls = 0;
		$this->appView->method($notifications ? 'queryAs' : 'query')->willReturnCallback(function (...$args) use ($notifications, &$calls): array {
			$calls++;
			$params = $args[$notifications ? 3 : 1];
			if ($calls === 1) {
				return $this->page($notifications, [102], 'second');
			}
			$this->assertSame('second', $params['cursor'] ?? null);
			if ($calls === 2) {
				throw new AtprotoException('Temporary AppView failure');
			}

			return $this->page($notifications, [101, 100], '');
		});
		$poller->poll(1);
		$pending = $this->checkpoint;
		$poller->poll(1);
		$this->assertSame($pending, $this->checkpoint);
		$this->assertSame(1, $this->failures);
		$poller->poll(1);
		$this->assertSame([self::uri(102), self::uri(101), self::uri(100)], $this->stored);
		$this->assertSame(self::stamp(102), $this->checkpoint);
	}

	#[DataProvider('readers')]
	public function testMalformedResponsesDoNotCompleteAPendingBacklog(bool $notifications): void {
		$poller = $this->poller($notifications);
		$this->appView->method($notifications ? 'queryAs' : 'query')->willReturnOnConsecutiveCalls($this->page($notifications, [102], 'second'), []);
		$poller->poll(1);
		$pending = $this->checkpoint;
		$poller->poll(1);
		$this->assertSame($pending, $this->checkpoint);
		$this->assertSame(1, $this->failures);
	}

	#[DataProvider('readers')]
	public function testAnInitialReadWalksTheBlueskyFeedButSnapshotsNotifications(bool $notifications): void {
		$this->checkpoint = '';
		$poller = $this->poller($notifications);
		$this->appView->method($notifications ? 'queryAs' : 'query')->willReturnCallback(function (...$args) use ($notifications): array {
			$params = $args[$notifications ? 3 : 1];
			if (!isset($params['cursor'])) {
				return $this->page($notifications, [102, 101], 'older');
			}
			$this->assertSame('older', $params['cursor']);

			return $this->page($notifications, [100, 99], '');
		});
		$poller->poll(1);
		if ($notifications) {
			$this->assertSame(self::stamp(102), $this->checkpoint);
			$this->assertSame([self::uri(102), self::uri(101)], $this->stored);
			return;
		}

		$this->assertStringContainsString('"since":""', $this->checkpoint, 'the first feed page retains an initial backfill checkpoint');
		$this->assertStringContainsString('"page":"older"', $this->checkpoint);
		$poller->poll(1);
		$this->assertSame(self::stamp(102), $this->checkpoint);
		$this->assertSame([self::uri(102), self::uri(101), self::uri(100), self::uri(99)], $this->stored);
	}

	#[DataProvider('readers')]
	public function testARepeatedRemoteCursorDoesNotSkipTheUnfinishedBacklog(bool $notifications): void {
		$poller = $this->poller($notifications);
		$this->appView->method($notifications ? 'queryAs' : 'query')->willReturnOnConsecutiveCalls($this->page($notifications, [103], 'second'), $this->page($notifications, [102], 'second'));
		$poller->poll(1);
		$pending = $this->checkpoint;
		$poller->poll(1);
		$this->assertSame($pending, $this->checkpoint);
		$this->assertSame(1, $this->failures);
	}

	#[DataProvider('readers')]
	public function testDistinctEventsWithTheWatermarkTimestampAreNotSkipped(bool $notifications): void {
		$poller = $this->poller($notifications);
		$first = $this->page($notifications, [101, 100], 'same-time');
		$second = $this->page($notifications, [102, 99], 'older');
		$setTime = static function (array &$page, int $index, string $time) use ($notifications): void {
			if ($notifications) {
				$page['notifications'][$index]['indexedAt'] = $time;
			} else {
				$page['feed'][$index]['post']['indexedAt'] = $time;
			}
		};
		$setTime($first, 1, self::stamp(100));
		$setTime($second, 0, self::stamp(100));
		$asked = [];
		$this->appView->method($notifications ? 'queryAs' : 'query')->willReturnCallback(function (...$args) use ($notifications, $first, $second, &$asked): array {
			$params = $args[$notifications ? 3 : 1];
			$cursor = $params['cursor'] ?? '';
			$asked[] = $cursor;

			return $cursor === '' ? $first : $second;
		});

		$poller->poll(1);
		$this->assertStringContainsString('same-time', $this->checkpoint, 'the first page must retain its continuation cursor');
		$poller->poll(1);

		$this->assertSame(['', 'same-time'], $asked);
		$this->assertSame([self::uri(101), self::uri(100), self::uri(102)], $this->stored);
		$this->assertSame(self::stamp(101), $this->checkpoint);
	}

	private function poller(bool $notifications): FeedPoller|NotificationPoller {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('syncCeiling')->willReturn(1);
		$this->appView = $this->createMock(AppViewClient::class);
		$watches = $this->createMock(AtprotoWatchRequest::class);
		$watches->method('getDue')->willReturnCallback(fn (): array => [new Watch(self::DID, 'alice.test', $this->checkpoint, self::NOW - 600, self::NOW, 0, '', 0)]);
		$watches->method('synced')->willReturnCallback(function (string $did, string $cursor, int $now, int $next): void {
			$this->checkpoint = $cursor;
			$this->scheduled[] = $next - $now;
		});
		$watches->method('failed')->willReturnCallback(function (): void {
			$this->failures++;
		});
		$store = $this->createMock(PostStore::class);
		$store->method('storeFeedItem')->willReturnCallback(function (array $item): int {
			$this->stored[] = $item['post']['uri'];

			return 1;
		});
		$store->method('storeByUri')->willReturnCallback(function (string $uri): bool {
			$this->stored[] = $uri;

			return true;
		});
		$actors = $this->createMock(BlueskyActorService::class);
		$blocklist = $this->createMock(Blocklist::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		if (!$notifications) {
			return new FeedPoller($config, $watches, $this->appView, $store, $actors, $blocklist, $this->createMock(LabelerService::class), $time, new NullLogger());
		}
		$identity = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('getByDid')->willReturn($identity);
		$identities->method('getAll')->willReturn([$identity]);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));

		return new NotificationPoller($config, $identities, $watches, $this->appView, $store, $this->createMock(LocalRecordResolver::class), new ActorMapper(), $actors, $blocklist, $this->createMock(ImportService::class), $this->createMock(NotificationService::class), $time, new NullLogger());
	}

	private function page(bool $notifications, array $ids, string $next): array {
		$items = [];
		foreach ($ids as $id) {
			$post = ['uri' => self::uri($id), 'indexedAt' => self::stamp($id), 'author' => ['did' => self::AUTHOR]];
			$items[] = $notifications ? $post + ['reason' => 'reply'] : ['post' => $post];
		}

		return [$notifications ? 'notifications' : 'feed' => $items, 'cursor' => $next];
	}

	private static function stamp(int $id): string {
		return gmdate('Y-m-d\TH:i:s.000\Z', self::NOW + $id);
	}

	private static function uri(int $id): string {
		return 'at://' . self::AUTHOR . '/app.bsky.feed.post/p' . $id;
	}
}
