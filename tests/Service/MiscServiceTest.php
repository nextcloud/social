<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\MiscService;
use OCP\IUserManager;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MiscServiceTest extends TestCase {
	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testLogTagsTheMessageWithAppAndLevel(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('log')
			->with(3, 'something happened', ['app' => 'social', 'level' => 3]);

		$service = new MiscService($logger, $this->createMock(IUserManager::class));
		$service->log('something happened', 3);
	}

	public function testLogDefaultsToLevelTwo(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('log')
			->with(2, 'default level', ['app' => 'social', 'level' => 2]);

		$service = new MiscService($logger, $this->createMock(IUserManager::class));
		$service->log('default level');
	}

	public function testGetNcVersionReturnsTheMajorVersion(): void {
		$version = $this->createMock(ServerVersion::class);
		$version->method('getVersion')->willReturn([31, 0, 2]);
		\OC::$server->register(ServerVersion::class, $version);

		$service = new MiscService($this->createMock(LoggerInterface::class), $this->createMock(IUserManager::class));

		$this->assertSame(31, $service->getNcVersion());
	}
}
