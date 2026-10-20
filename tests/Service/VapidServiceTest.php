<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use Minishlink\WebPush\VAPID;
use OCA\Social\Security\PrivateKeyCipher;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\VapidService;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class VapidServiceTest extends TestCase {
	private IAppConfig&Stub $appConfig;
	private ConfigService&Stub $configService;
	private PrivateKeyCipher&Stub $cipher;
	private ILockingProvider&Stub $locks;
	private string $stored = '';

	protected function setUp(): void {
		$this->appConfig = $this->createStub(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->stored === '' ? $default : $this->stored
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored = $value;
				return true;
			}
		);
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getSocialUrl')->willReturn('https://social.example/apps/social/');
		$this->cipher = $this->createStub(PrivateKeyCipher::class);
		$this->cipher->method('seal')->willReturnCallback(static fn (string $key): string => 'sealed:' . $key);
		$this->cipher->method('open')->willReturnCallback(static fn (string $key): string => str_starts_with($key, 'sealed:') ? substr($key, 7) : '');
		$this->locks = $this->createStub(ILockingProvider::class);
	}

	public function testCreatesAndPersistsAStableEncryptedKeyPair(): void {
		$service = $this->service();
		$publicKey = $service->publicKey();
		$auth = $service->auth();

		$this->assertSame($publicKey, $auth['publicKey']);
		$this->assertSame('https://social.example/apps/social/', $auth['subject']);
		$this->assertNotSame('', $auth['privateKey']);
		$persisted = json_decode($this->stored, true, 8, JSON_THROW_ON_ERROR);
		$this->assertSame('sealed:' . $auth['privateKey'], $persisted['privateKey']);
		$this->assertSame($auth['publicKey'], $persisted['publicKey']);
		$this->assertSame($auth, $this->service()->auth());
		$this->assertSame($publicKey, $this->service()->publicKey());
	}

	public function testStoredKeysAreUsableAsVapidCredentials(): void {
		$auth = $this->service()->auth();
		$validated = VAPID::validate($auth);

		$this->assertSame(65, strlen($validated['publicKey']));
		$this->assertSame(32, strlen($validated['privateKey']));
	}

	public function testRefusesMalformedStoredKeysInsteadOfRotatingThem(): void {
		$this->stored = '{"publicKey":"broken","privateKey":"sealed:secret"}';

		$this->expectException(\RuntimeException::class);
		$this->service()->publicKey();
	}

	private function service(): VapidService {
		return new VapidService($this->appConfig, $this->configService, $this->cipher, $this->locks);
	}
}
