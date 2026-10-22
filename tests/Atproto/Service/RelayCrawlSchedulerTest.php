<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Service;

use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Firehose\FirehoseDaemon;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Service\AtprotoStatusService;
use OCA\Social\Atproto\Service\RelayClient;
use OCA\Social\Atproto\Service\RelayCrawlScheduler;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class RelayCrawlSchedulerTest extends TestCase {
	/** @var array<string, string> */
	private array $values = [];

	private function scheduler(int $now, ?array $status, bool $enabled, array $results, ?int &$calls = null, bool $indexingGap = false): RelayCrawlScheduler {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn($enabled);
		$firehose = $this->createMock(FirehoseDaemon::class);
		$firehose->method('status')->willReturn($status);
		$health = $this->createMock(AtprotoStatusService::class);
		$health->method('hasRelayIndexingGap')->willReturn($indexingGap);
		$relays = $this->createMock(RelayClient::class);
		$relays->method('requestCrawl')->willReturnCallback(static function () use ($results, &$calls): array {
			$calls = ($calls ?? 0) + 1;

			return $results;
		});
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getAppValue')->willReturnCallback(fn (string $key): string => $this->values[$key] ?? '0');
		$configService->method('setAppValue')->willReturnCallback(function (string $key, string $value): void {
			$this->values[$key] = $value;
		});

		return new RelayCrawlScheduler($config, $relays, $firehose, $health, $configService, $time, new NullLogger());
	}

	public function testFirstEnabledMaintenanceRunRequestsCrawlOnce(): void {
		$calls = 0;
		$scheduler = $this->scheduler(1000, null, true, ['https://relay.example' => 'ok'], $calls);

		$scheduler->run();
		$scheduler->run();

		self::assertSame(1, $calls);
		self::assertSame('1000', $this->values[ConfigService::ATPROTO_CRAWL_ATTEMPTED]);
		self::assertSame('1000', $this->values[ConfigService::ATPROTO_CRAWL_ACCEPTED]);
	}

	public function testFailedInitialRequestIsRetriedAfterBackoff(): void {
		$this->values[ConfigService::ATPROTO_CRAWL_ATTEMPTED] = '1000';
		$calls = 0;
		$scheduler = $this->scheduler(1000 + 15 * 60, null, true, ['https://relay.example' => 'not reached'], $calls);

		$scheduler->run();

		self::assertSame(1, $calls);
		self::assertSame((string)(1000 + 15 * 60), $this->values[ConfigService::ATPROTO_CRAWL_ATTEMPTED]);
	}

	public function testLongFirehoseOutageTriggersThrottledRecrawl(): void {
		$now = 1000 + EventService::REPLAY_WINDOW + 7 * 3600;
		$this->values[ConfigService::ATPROTO_CRAWL_ATTEMPTED] = (string)($now - 7 * 3600);
		$this->values[ConfigService::ATPROTO_CRAWL_ACCEPTED] = '1000';
		$calls = 0;
		$status = ['running' => false, 'pid' => 0, 'started' => 100, 'seen' => 1000, 'head' => 1, 'subscribers' => 0];
		$scheduler = $this->scheduler($now, $status, true, ['https://relay.example' => 'ok'], $calls);

		$scheduler->run();
		$scheduler->run();

		self::assertSame(1, $calls);
		self::assertSame((string)$now, $this->values[ConfigService::ATPROTO_CRAWL_ATTEMPTED]);
	}

	public function testDisabledProtocolDoesNotRequestCrawl(): void {
		$calls = 0;
		$this->scheduler(1000, null, false, ['https://relay.example' => 'ok'], $calls)->run();

		self::assertSame(0, $calls);
		self::assertSame([], $this->values);
	}

	public function testAcceptedButUnindexedRepositoryIsRecrawledAfterIndexingRetry(): void {
		$now = 1000 + 3600;
		$this->values[ConfigService::ATPROTO_CRAWL_ATTEMPTED] = '1000';
		$this->values[ConfigService::ATPROTO_CRAWL_ACCEPTED] = '1000';
		$calls = 0;
		$scheduler = $this->scheduler($now, null, true, ['https://relay.example' => 'ok'], $calls, true);

		$scheduler->run();
		$scheduler->run();

		self::assertSame(1, $calls);
		self::assertSame((string)$now, $this->values[ConfigService::ATPROTO_CRAWL_ATTEMPTED]);
	}

	public function testAcceptedButUnindexedRepositoryWaitsForTheIndexingRetry(): void {
		$now = 1000 + 59 * 60;
		$this->values[ConfigService::ATPROTO_CRAWL_ATTEMPTED] = '1000';
		$this->values[ConfigService::ATPROTO_CRAWL_ACCEPTED] = '1000';
		$calls = 0;
		$scheduler = $this->scheduler($now, null, true, ['https://relay.example' => 'ok'], $calls, true);

		$scheduler->run();

		self::assertSame(0, $calls);
		self::assertSame('1000', $this->values[ConfigService::ATPROTO_CRAWL_ATTEMPTED]);
	}
}
