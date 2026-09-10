<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Command;

use OCA\Social\Command\StreamPrune;
use OCA\Social\Service\ConfigService;
use OCP\Server;

/**
 * `social:stream:prune` deletes content, so only its two non-deleting paths
 * are exercised here: the disabled-retention guard and --dry-run.
 */
class StreamPruneTest extends CommandTestCase {
	private string $retention = '';

	protected function setUp(): void {
		parent::setUp();
		$this->retention = (string)Server::get(ConfigService::class)
			->getAppValue(ConfigService::SOCIAL_RETENTION_DAYS);
	}

	protected function tearDown(): void {
		Server::get(ConfigService::class)
			->setAppValue(ConfigService::SOCIAL_RETENTION_DAYS, $this->retention);
		parent::tearDown();
	}

	public function testRetentionDisabledPrunesNothingAndSaysSo(): void {
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_RETENTION_DAYS, '0');
		$tester = $this->tester(StreamPrune::class);

		$code = $this->runNonInteractive($tester, []);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('retention is disabled', $tester->getDisplay());
	}

	public function testDaysOverridesTheDisabledSetting(): void {
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_RETENTION_DAYS, '0');
		$tester = $this->tester(StreamPrune::class);

		$code = $this->runNonInteractive($tester, ['--days' => '3650', '--dry-run' => true]);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('would be pruned', $tester->getDisplay());
	}

	public function testDryRunCountsWithoutDeleting(): void {
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_RETENTION_DAYS, '30');
		$tester = $this->tester(StreamPrune::class);

		$code = $this->runNonInteractive($tester, ['--dry-run' => true]);
		$display = $tester->getDisplay();

		$this->assertSame(0, $code);
		$this->assertStringContainsString('would be pruned', $display);
		$this->assertStringNotContainsString('cached documents removed', $display);
	}
}
