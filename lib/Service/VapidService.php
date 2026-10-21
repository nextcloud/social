<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use JsonException;
use Minishlink\WebPush\VAPID;
use OCA\Social\AppInfo\Application;
use OCA\Social\Security\PrivateKeyCipher;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use RuntimeException;
use Throwable;

/** The instance's stable VAPID identity for Mastodon Web Push. */
class VapidService {
	public const CONFIG_KEY = 'mastodon_vapid_keys';
	private const LOCK = 'social-mastodon-vapid-keys';

	/** @var array{publicKey: string, privateKey: string}|null */
	private ?array $keys = null;

	public function __construct(
		private IAppConfig $appConfig,
		private ConfigService $configService,
		private PrivateKeyCipher $cipher,
		private ILockingProvider $lockingProvider,
	) {
	}

	/** The public key clients use when making a browser subscription. */
	public function publicKey(): string {
		return $this->loadKeys()['publicKey'];
	}

	/** @return array{subject: string, publicKey: string, privateKey: string} */
	public function auth(): array {
		$keys = $this->loadKeys();

		return [
			'subject' => $this->configService->getSocialUrl(),
			'publicKey' => $keys['publicKey'],
			'privateKey' => $keys['privateKey'],
		];
	}

	/** @return array{publicKey: string, privateKey: string} */
	private function loadKeys(): array {
		if ($this->keys !== null) {
			return $this->keys;
		}

		$stored = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
		if ($stored !== '') {
			return $this->keys = $this->open($stored);
		}

		$this->lockingProvider->acquireLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$stored = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
			if ($stored !== '') {
				return $this->keys = $this->open($stored);
			}

			$keys = VAPID::createVapidKeys();
			try {
				$stored = json_encode([
					'publicKey' => $keys['publicKey'],
					'privateKey' => $this->cipher->seal($keys['privateKey']),
				], JSON_THROW_ON_ERROR);
			} catch (JsonException $e) {
				throw new RuntimeException('Could not store the Web Push key pair', 0, $e);
			}
			$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_KEY, $stored);

			return $this->keys = $keys;
		} finally {
			$this->lockingProvider->releaseLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return array{publicKey: string, privateKey: string} */
	private function open(string $stored): array {
		try {
			$values = json_decode($stored, true, 8, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException('The stored Web Push key pair is invalid', 0, $e);
		}

		if (!is_array($values)
			|| !is_string($values['publicKey'] ?? null)
			|| !is_string($values['privateKey'] ?? null)) {
			throw new RuntimeException('The stored Web Push key pair is incomplete');
		}

		$privateKey = $this->cipher->open($values['privateKey']);
		if ($privateKey === '') {
			throw new RuntimeException('The stored Web Push private key cannot be decrypted');
		}
		try {
			VAPID::validate([
				'subject' => $this->configService->getSocialUrl(),
				'publicKey' => $values['publicKey'],
				'privateKey' => $privateKey,
			]);
		} catch (Throwable $e) {
			throw new RuntimeException('The stored Web Push key pair is invalid', 0, $e);
		}

		return ['publicKey' => $values['publicKey'], 'privateKey' => $privateKey];
	}
}
