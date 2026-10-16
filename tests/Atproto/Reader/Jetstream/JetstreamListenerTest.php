<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader\Jetstream;

use OCA\Social\Atproto\Model\Watch;
use OCA\Social\Atproto\Reader\Jetstream\JetstreamClient;
use OCA\Social\Atproto\Reader\Jetstream\JetstreamEvents;
use OCA\Social\Atproto\Reader\Jetstream\JetstreamListener;
use OCA\Social\Atproto\Reader\Jetstream\JetstreamState;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DurableCache;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class JetstreamListenerTest extends TestCase {
	private const START = 1760000000;

	private int $now = self::START;
	/** how far the clock moves on each reading */
	private int $tick = 0;
	/** @var list<int> when each status report says it was made, from the start */
	private array $reports = [];
	/** @var array<string, string> app values */
	private array $values = [];
	/** @var array<string, mixed> what the durable cache's table holds, by key */
	private array $shared = [];
	/** @var list<array{0: string, 1: int}> each cursor kept, with the clock it was kept at */
	private array $kept = [];
	/** @var Watch[] */
	private array $watched = [];
	/** @var JetstreamClient&MockObject */
	private JetstreamClient $client;
	/** @var JetstreamEvents&MockObject */
	private JetstreamEvents $events;
	private JetstreamListener $listener;

	protected function setUp(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('jetstream')->willReturn('wss://jetstream.test');
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getAppValue')->willReturnCallback(fn (string $key): string => $this->values[$key] ?? '');
		$configService->method('deleteAppValue')->willReturnCallback(function (string $key): void {
			unset($this->values[$key]);
		});
		// an app-config write invalidates the config cache of every request
		$configService->expects($this->never())->method('setAppValue');
		$durableCache = $this->createStub(DurableCache::class);
		$durableCache->method('getShared')->willReturnCallback(fn (string $namespace, string $key): mixed => $this->shared[$key] ?? null);
		$durableCache->method('setShared')->willReturnCallback(function (string $namespace, string $key, mixed $value, int $ttl): void {
			$this->shared[$key] = $value;
			if ($key === 'cursor') {
				$this->kept[] = [(string)$value, $this->now - self::START];
			}
			if ($key === 'status') {
				$this->reports[] = $value['seen'] - self::START;
			}
		});
		$state = new JetstreamState($durableCache, $configService);
		$watches = $this->createMock(AtprotoWatchRequest::class);
		$watches->method('getAll')->willReturnCallback(fn (): array => $this->watched);
		$this->client = $this->createMock(JetstreamClient::class);
		$this->events = $this->createMock(JetstreamEvents::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(function (): int {
			$now = $this->now;
			$this->now += $this->tick;

			return $now;
		});
		$this->listener = new JetstreamListener($config, $state, $watches, $this->events, $this->client, $time, new NullLogger());
	}

	/**
	 * A connected listener whose reads answer these batches in turn, each
	 * read moving the clock on by `$seconds`; a batch of null is a lost
	 * connection.
	 *
	 * @param list<list<string>|null> $batches
	 */
	private function connected(array $batches, int $seconds = 0): void {
		$this->watched = [new Watch('did:plc:a', 'a.test', '', 0, 0, 0, '', 0)];
		$this->client->method('isConnected')->willReturn(true);
		$this->client->method('read')->willReturnCallback(function () use (&$batches, $seconds): array {
			$this->now += $seconds;
			if ($batches === []) {
				return [];
			}
			$batch = array_shift($batches);

			return $batch ?? throw new \RuntimeException('gone');
		});
	}

	private static function event(int $n): string {
		return '{"did":"did:plc:a","time_us":' . (self::START * 1000000 + $n) . ',"kind":"commit"}';
	}

	public function testTheAddressAsksForTheCollectionsAndForNothingUntilTheAccountsAreNamed(): void {
		$this->assertSame(
			'wss://jetstream.test/subscribe?wantedCollections=app.bsky.feed.post&wantedCollections=app.bsky.feed.repost&wantedCollections=app.bsky.actor.profile&wantedCollections=app.bsky.graph.block&requireHello=true&cursor=42',
			JetstreamListener::url('wss://jetstream.test/', 42),
		);
		$this->assertStringStartsWith('ws://127.0.0.1:6008/subscribe?wanted', JetstreamListener::url('ws://127.0.0.1:6008/subscribe'));
		$this->assertSame(
			['type' => 'options_update', 'payload' => ['wantedCollections' => JetstreamEvents::COLLECTIONS, 'wantedDids' => ['did:plc:a'], 'maxMessageSizeBytes' => 0]],
			json_decode(JetstreamListener::optionsUpdate(['did:plc:a']), true),
		);
	}

	/**
	 * A kept cursor is a database write, so one is written every few
	 * seconds, on stopping, on a lost connection, and after a burst of
	 * events — not on every read.
	 */
	public function testTheCursorIsKeptOnStoppingNotOnEveryRead(): void {
		$this->connected([[self::event(1)], [self::event(2)], [self::event(3)], []]);

		$this->listener->listen(static fn (): null => null, 0, true);

		$this->assertSame([[(string)(self::START * 1000000 + 3), 0]], $this->kept, 'once, where it got to');
	}

	public function testTheCursorIsKeptEveryFewSecondsAndWhenTheConnectionIsLost(): void {
		$this->connected([[self::event(1)], [self::event(2)], [self::event(3)], null], 3);

		$this->listener->listen(static fn (): null => null, 0, true);

		$this->assertSame([
			[(string)(self::START * 1000000 + 2), 6],
			[(string)(self::START * 1000000 + 3), 12],
		], $this->kept, 'six seconds in, then on the loss; nothing more on stopping, it is kept already');
	}

	public function testABurstOfEventsKeepsTheCursorToo(): void {
		$burst = array_map(static fn (int $n): string => self::event($n), range(1, JetstreamListener::KEEP_EVENTS));
		$this->connected([$burst, [self::event(JetstreamListener::KEEP_EVENTS + 1)], []]);

		$this->listener->listen(static fn (): null => null, 0, true);

		$this->assertSame([
			[(string)(self::START * 1000000 + JetstreamListener::KEEP_EVENTS), 0],
			[(string)(self::START * 1000000 + JetstreamListener::KEEP_EVENTS + 1), 0],
		], $this->kept, 'after the burst, and the last one on stopping');
	}

	/** An empty list of accounts would be the whole network. */
	public function testNobodyFollowedIsNoConnection(): void {
		$this->client->expects($this->never())->method('connect');

		$this->assertSame(0, $this->listener->listen(static fn (): null => null, 0, true));
		$this->assertFalse($this->listener->status()['connected']);
	}

	/**
	 * A report is a write as well: watching nobody, the listener reports
	 * as seldom as when connected, not on every idle second.
	 */
	public function testAnIdleListenerReportsEveryFewSecondsNotEverySecond(): void {
		$this->tick = 1;

		$this->listener->listen(static fn (): null => null, 4);

		$this->assertSame([2, 5], $this->reports, 'once on starting, then only on stopping');
		$this->assertFalse($this->listener->status()['running']);
	}

	public function testTheFollowedAccountsAreAskedForFromAFewSecondsBeforeWhereItStopped(): void {
		$this->watched = [new Watch('did:plc:b', 'b.test', '', 0, 0, 0, '', 0), new Watch('did:plc:a', 'a.test', '', 0, 0, 0, '', 0)];
		// kept in app config by an older version, and moved over once
		$this->values[ConfigService::ATPROTO_JETSTREAM_CURSOR] = '1760000000000000';
		$connected = false;
		$this->client->method('isConnected')->willReturnCallback(static function () use (&$connected): bool {
			return $connected;
		});
		$this->client->expects($this->once())->method('connect')->with($this->stringContains('cursor=1759999995000000'))->willReturnCallback(static function () use (&$connected): void {
			$connected = true;
		});
		$this->client->expects($this->once())->method('send')->with(JetstreamListener::optionsUpdate(['did:plc:a', 'did:plc:b']));
		$this->client->method('read')->willReturnOnConsecutiveCalls(['{"did":"did:plc:a","time_us":1760000001000000,"kind":"commit"}', 'not json'], []);
		$this->events->expects($this->once())->method('setWatched')->with(['did:plc:a', 'did:plc:b']);
		$this->events->expects($this->once())->method('handle')->with($this->callback(static fn (array $event): bool => $event['did'] === 'did:plc:a'));

		$this->assertSame(0, $this->listener->listen(static fn (): null => null, 0, true));

		$this->assertSame(1760000001000000, $this->shared['cursor'], 'where it got to is kept');
		$this->assertArrayNotHasKey(ConfigService::ATPROTO_JETSTREAM_CURSOR, $this->values, 'and no longer in app config');
		$this->assertSame(2, $this->listener->status()['accounts']);
		$this->assertFalse($this->listener->status()['running'], 'and it says it stopped');
	}
}
