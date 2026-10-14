<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Atproto\Chat\ChatPoller;
use OCA\Social\Atproto\Reader\FeedPoller;
use OCA\Social\Atproto\Reader\NotificationPoller;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Cron\AtprotoSync;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class AtprotoSyncTest extends TestCase {
	private const NOW = 1760000000;

	private int $now = self::NOW;
	/** @var FeedPoller&MockObject */
	private FeedPoller $feeds;
	/** @var NotificationPoller&MockObject */
	private NotificationPoller $notifications;
	/** @var ChatPoller&MockObject */
	private ChatPoller $chat;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;
	private AtprotoSync $job;

	protected function setUp(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$this->feeds = $this->createMock(FeedPoller::class);
		$this->notifications = $this->createMock(NotificationPoller::class);
		$this->chat = $this->createMock(ChatPoller::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->job = new AtprotoSync($time, $config, $this->feeds, $this->notifications, $this->chat, $this->logger);
	}

	private function runJob(): void {
		(new \ReflectionMethod(AtprotoSync::class, 'run'))->invoke($this->job, null);
	}

	public function testEachStepIsGivenTheSameDeadlineWellInsideTheInterval(): void {
		$deadline = self::NOW + AtprotoSync::MAX_DURATION;
		$this->feeds->expects($this->once())->method('poll')->with(FeedPoller::BATCH, $deadline)->willReturn(['watches' => 0, 'stored' => 0, 'requests' => 0]);
		$this->notifications->expects($this->once())->method('poll')->with(NotificationPoller::BATCH, $deadline)->willReturn(['accounts' => 0, 'handled' => 0, 'requests' => 0]);
		$this->chat->expects($this->once())->method('poll')->with(ChatPoller::BATCH, $deadline)->willReturn(['accounts' => 0, 'handled' => 0]);
		$this->logger->expects($this->never())->method('warning');

		$this->runJob();

		$this->assertLessThan(2 * 60, AtprotoSync::MAX_DURATION, 'two passes cannot meet');
	}

	/** A slow AppView ends the pass at the budget; what is still due is read on the next one. */
	public function testAStepThatSpendsTheBudgetLeavesTheRestToTheNextRun(): void {
		$this->feeds->expects($this->once())->method('poll')->willReturnCallback(function (): array {
			$this->now += AtprotoSync::MAX_DURATION;

			return ['watches' => 1, 'stored' => 0, 'requests' => 1];
		});
		$this->notifications->expects($this->never())->method('poll');
		$this->chat->expects($this->never())->method('poll');
		$this->logger->expects($this->exactly(2))->method('warning')->with($this->stringContains('out of time'));

		$this->runJob();
	}
}
