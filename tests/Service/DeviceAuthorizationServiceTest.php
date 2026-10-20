<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\DeviceAuthorizationRequest;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DeviceAuthorizationService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class DeviceAuthorizationServiceTest extends TestCase {
	public function testCreateReturnsShortCodeAndUsesTheReachableVerificationAddress(): void {
		$requests = new InMemoryDeviceAuthorizationRequest();
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_800_000_000);
		$config = $this->createMock(ConfigService::class);
		$config->method('getSocialUrl')->willReturn('https://cloud.example/apps/social/');
		$config->expects(self::once())->method('getCloudUrl')->with(true)->willReturn('https://cloud.example/');
		$check = $this->createStub(CheckService::class);
		$check->method('clientApiRootIsKnownGood')->willReturn(true);
		$service = new DeviceAuthorizationService($requests, $time, $config, $check);

		$result = $service->create('client-1', 'read write');

		self::assertSame(43, strlen($result['device_code']));
		self::assertMatchesRegularExpression('/^[BCDFGHJKLMNPQRSTVWXZ]{4}-[BCDFGHJKLMNPQRSTVWXZ]{4}$/', $result['user_code']);
		self::assertSame('https://cloud.example/oauth/device', $result['verification_uri']);
		self::assertStringContainsString('user_code=' . $result['user_code'], $result['verification_uri_complete']);
		self::assertSame(600, $result['expires_in']);
		self::assertSame(5, $result['interval']);
		self::assertSame(hash('sha256', $result['device_code']), $requests->created['device_hash']);
		self::assertSame('read write', $requests->created['scopes']);
	}

	public function testUserCodeIsCaseInsensitiveAndIgnoresSeparators(): void {
		self::assertSame('BCDFGHJK', DeviceAuthorizationService::normalizeUserCode('bcdf-ghjk'));
		self::assertSame('BCDFGHJK', DeviceAuthorizationService::normalizeUserCode("bcdf \tghjk"));
		self::assertNull(DeviceAuthorizationService::normalizeUserCode('ABCDEFGH'));
		self::assertNull(DeviceAuthorizationService::normalizeUserCode('BCDF-GH'));
	}

	public function testUnknownExpiredOrUsedUserCodeIsNotInspectable(): void {
		$requests = new InMemoryDeviceAuthorizationRequest();
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_800_000_000);
		$service = new DeviceAuthorizationService($requests, $time, $this->createStub(ConfigService::class), $this->createStub(CheckService::class));

		self::assertNull($service->inspect('ABCDEFGH'));
		$requests->byCode = ['id' => 1, 'client_id' => 'client-1', 'scopes' => 'read', 'expires' => 1_799_999_999, 'status' => 'pending'];
		self::assertNull($service->inspect('BCDF-GHJK'));
		$requests->byCode['expires'] = 1_800_000_001;
		$requests->byCode['status'] = 'issued';
		self::assertNull($service->inspect('BCDF-GHJK'));
	}
}

class InMemoryDeviceAuthorizationRequest extends DeviceAuthorizationRequest {
	public array $created = [];
	public ?array $byCode = null;
	public array $pollResult = [];

	public function __construct() {
	}

	public function create(string $deviceHash, string $userCode, string $clientId, string $scopes, int $expires, int $created): void {
		$this->created = compact('deviceHash', 'userCode', 'clientId', 'scopes', 'expires', 'created') + ['device_hash' => $deviceHash];
	}

	public function byUserCode(string $userCode): ?array {
		return $this->byCode;
	}

	public function byDeviceHash(string $deviceHash, string $clientId): ?array {
		return ['device_hash' => $deviceHash, 'id' => 1];
	}

	public function poll(int $id, int $now): array {
		return $this->pollResult;
	}

	public function setDecision(int $id, string $status, string $userId): bool {
		return true;
	}
}
