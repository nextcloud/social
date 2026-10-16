<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Cron\RepublishAvatars;
use OCA\Social\Migration\RepublishAvatarAddresses;
use OCA\Social\Service\ConfigService;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class RepublishAvatarAddressesTest extends TestCase {
	/** @var array<string, string> */
	private array $app = [];

	private function step(IJobList $jobList): RepublishAvatarAddresses {
		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValueInt')->willReturnCallback(fn (string $key): int => (int)($this->app[$key] ?? 0));
		$config->method('setAppValue')->willReturnCallback(function (string $key, $value): void {
			$this->app[$key] = (string)$value;
		});

		return new RepublishAvatarAddresses($jobList, $config);
	}

	public function testTheWalkIsQueuedOnceAcrossUpgrades(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('add')->with(RepublishAvatars::class, ['after' => '']);

		$this->step($jobList)->run($this->createStub(IOutput::class));
		$this->step($jobList)->run($this->createStub(IOutput::class));
	}

	public function testItRunsAfterEveryUpgrade(): void {
		$info = simplexml_load_file(__DIR__ . '/../../appinfo/info.xml');
		$this->assertNotFalse($info);
		$steps = array_map('trim', array_map('strval', iterator_to_array($info->{'repair-steps'}->{'post-migration'}->step, false)));

		$this->assertContains(RepublishAvatarAddresses::class, $steps);
	}
}
