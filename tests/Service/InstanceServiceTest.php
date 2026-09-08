<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\InstancesRequest;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Instance;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\MiscService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class InstanceServiceTest extends TestCase {
	private InstancesRequest|MockObject $instancesRequest;
	private IConfig|MockObject $config;
	private InstanceService $service;

	protected function setUp(): void {
		$this->instancesRequest = $this->createMock(InstancesRequest::class);
		$this->config = $this->createMock(IConfig::class);
		$this->service = new InstanceService(
			$this->instancesRequest,
			$this->createMock(ConfigService::class),
			$this->createMock(MiscService::class),
			$this->config,
		);
	}

	public function testCreateLocalBuildsTheInstanceFromAppAndThemingConfig(): void {
		$this->config->method('getAppValue')
			->willReturnCallback(fn (string $app, string $key, $default) => match ([$app, $key]) {
				['social', 'installed_version'] => '0.9.1',
				['theming', 'slogan'] => 'Our slogan',
				['theming', 'name'] => 'Our Cloud',
				default => $default,
			});
		$saved = null;
		$this->instancesRequest->expects($this->once())
			->method('save')
			->willReturnCallback(function (Instance $instance) use (&$saved) {
				$saved = $instance;
			});

		$instance = $this->service->createLocal();

		$this->assertSame($saved, $instance);
		$this->assertTrue($instance->isLocal());
		$this->assertSame('0.9.1', $instance->getVersion());
		$this->assertSame('Our slogan', $instance->getDescription());
		$this->assertSame('Our Cloud', $instance->getTitle());
		$this->assertFalse($instance->isApprovalRequired());
	}

	public function testCreateLocalUsesNextcloudDefaultsWhenThemingIsUnset(): void {
		$this->config->method('getAppValue')
			->willReturnCallback(fn (string $app, string $key, $default) => $default);

		$instance = $this->service->createLocal();

		$this->assertSame('0.0', $instance->getVersion());
		$this->assertSame('a safe home for your data', $instance->getDescription());
		$this->assertSame('Nextcloud Social', $instance->getTitle());
		$this->assertSame('Nextcloud Social', $instance->jsonSerialize()['title']);
	}

	public function testGetLocalReturnsTheStoredInstanceInTheRequestedFormat(): void {
		$stored = (new Instance())->setTitle('stored');
		$this->instancesRequest->expects($this->once())
			->method('getLocal')
			->with(ACore::FORMAT_ACTIVITYPUB)
			->willReturn($stored);
		$this->instancesRequest->expects($this->never())->method('save');

		$this->assertSame($stored, $this->service->getLocal(ACore::FORMAT_ACTIVITYPUB));
	}

	public function testGetLocalCreatesTheInstanceWhenMissing(): void {
		$this->instancesRequest->method('getLocal')
			->with(ACore::FORMAT_LOCAL)
			->willThrowException(new InstanceDoesNotExistException());
		$this->config->method('getAppValue')
			->willReturnCallback(fn (string $app, string $key, $default) => $default);
		$this->instancesRequest->expects($this->once())->method('save');

		$instance = $this->service->getLocal();

		$this->assertTrue($instance->isLocal());
		$this->assertSame('Nextcloud Social', $instance->getTitle());
	}
}
