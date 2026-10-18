<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\VideoPosters;
use OCA\Social\Service\VideoPosterBackfill;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Poster frames for the stored videos without one, a few every quarter of an hour. */
class VideoPostersTest extends TestCase {
	private function job(VideoPosterBackfill $backfill, ?LoggerInterface $logger = null): VideoPosters {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_757_937_600);

		return new VideoPosters($time, $backfill, $logger ?? $this->createStub(LoggerInterface::class));
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testItRunsEveryQuarterOfAnHour(): void {
		$job = $this->job($this->createStub(VideoPosterBackfill::class));

		$this->assertInstanceOf(TimedJob::class, $job);
		$this->assertSame(900, (new \ReflectionProperty(TimedJob::class, 'interval'))->getValue($job));
	}

	public function testEachRunTakesTheNextFewVideos(): void {
		$backfill = $this->createMock(VideoPosterBackfill::class);
		$backfill->expects($this->once())->method('next')->willReturn(3);

		$this->job($backfill)->start($this->createStub(IJobList::class));
	}

	/** A run that fails is logged and the job runs again next time. */
	public function testAFailingRunIsLoggedRatherThanThrown(): void {
		$backfill = $this->createStub(VideoPosterBackfill::class);
		$backfill->method('next')->willThrowException(new \RuntimeException('storage is gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->job($backfill, $logger)->start($this->createStub(IJobList::class));
	}
}
