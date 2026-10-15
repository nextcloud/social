<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\StarterPackSources;
use OCA\Social\Service\CollectionPackService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
class StarterPackSourcesTest extends TestCase {
	private function runJob(StarterPackSources $job): void {
		(new ReflectionMethod(StarterPackSources::class, 'run'))->invoke($job, null);
	}

	public function testItReadsTheSourcesOnceADay(): void {
		$service = $this->createMock(CollectionPackService::class);
		$service->expects($this->once())->method('refresh')
			->willReturn(['sources' => 2, 'packs' => 6, 'members' => 40, 'refused' => 0, 'failed' => 0]);
		$job = new StarterPackSources($this->createStub(ITimeFactory::class), $service, new NullLogger());

		$this->runJob($job);

		$this->assertSame(24 * 3600, $job->getInterval());
	}

	public function testOneRunAtATimeReadsUntilItsDeadline(): void {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_790_000_000);
		$service = $this->createMock(CollectionPackService::class);
		$service->expects($this->once())->method('refresh')
			->with(1_790_000_000 + StarterPackSources::MAX_DURATION)
			->willReturn(['sources' => 1, 'packs' => 0, 'members' => 0, 'refused' => 0, 'failed' => 1]);
		$job = new StarterPackSources($time, $service, new NullLogger());

		$this->runJob($job);

		$this->assertFalse($job->getAllowParallelRuns());
	}

	public function testAFailedPassIsLoggedRatherThanThrown(): void {
		$service = $this->createStub(CollectionPackService::class);
		$service->method('refresh')->willThrowException(new RuntimeException('down'));

		$this->runJob(new StarterPackSources($this->createStub(ITimeFactory::class), $service, new NullLogger()));

		$this->addToAssertionCount(1);
	}
}
