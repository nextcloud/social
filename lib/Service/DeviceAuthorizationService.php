<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\DeviceAuthorizationRequest;
use OCP\AppFramework\Utility\ITimeFactory;

/** RFC 8628 device codes and their one-time human approval. */
class DeviceAuthorizationService {
	public const LIFETIME = 600;
	public const INTERVAL = 5;
	private const USER_CODE_ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ';

	public function __construct(
		private DeviceAuthorizationRequest $requests,
		private ITimeFactory $time,
		private ConfigService $config,
		private CheckService $checkService,
	) {
	}

	public function create(string $clientId, string $scopes): array {
		$deviceCode = self::base64Url(random_bytes(32));
		do {
			$userCode = self::userCode();
		} while ($this->requests->byUserCode($userCode) !== null);
		$now = $this->time->getTime();
		$this->requests->create(hash('sha256', $deviceCode), $userCode, $clientId, $scopes, $now + self::LIFETIME, $now);
		$base = $this->checkService->clientApiRootIsKnownGood()
			? rtrim($this->config->getCloudUrl(true), '/')
			: rtrim($this->config->getSocialUrl(), '/');
		$verification = $base . '/oauth/device';

		return [
			'device_code' => $deviceCode,
			'user_code' => substr($userCode, 0, 4) . '-' . substr($userCode, 4),
			'verification_uri' => $verification,
			'verification_uri_complete' => $verification . '?user_code=' . substr($userCode, 0, 4) . '-' . substr($userCode, 4),
			'expires_in' => self::LIFETIME,
			'interval' => self::INTERVAL,
		];
	}

	public function decide(string $code, string $userId, bool $allow): bool {
		$normalized = self::normalizeUserCode($code);
		if ($normalized === null || strlen($normalized) !== 8) {
			return false;
		}
		$row = $this->requests->byUserCode($normalized);
		if ($row === null || (int)$row['expires'] <= $this->time->getTime() || $row['status'] !== 'pending') {
			return false;
		}

		return $this->requests->setDecision((int)$row['id'], $allow ? 'allowed' : 'denied', $allow ? $userId : '');
	}

	public function inspect(string $code): ?array {
		$normalized = self::normalizeUserCode($code);
		if ($normalized === null) {
			return null;
		}
		$row = $this->requests->byUserCode($normalized);
		if ($row === null || (int)$row['expires'] <= $this->time->getTime() || $row['status'] !== 'pending') {
			return null;
		}

		return ['client_id' => (string)$row['client_id'], 'scopes' => (string)$row['scopes']];
	}

	public function poll(string $deviceCode, string $clientId): array {
		if ($deviceCode === '' || strlen($deviceCode) > 256) {
			return ['error' => 'invalid_grant'];
		}
		$hash = hash('sha256', $deviceCode);
		$row = $this->requests->byDeviceHash($hash, $clientId);
		if ($row === null || !hash_equals((string)$row['device_hash'], $hash)) {
			return ['error' => 'invalid_grant'];
		}

		return $this->requests->poll((int)$row['id'], $this->time->getTime());
	}

	public static function normalizeUserCode(string $code): ?string {
		$normalized = strtoupper(preg_replace('/[-\s]/u', '', trim($code)) ?? '');
		return preg_match('/^[BCDFGHJKLMNPQRSTVWXZ]{8}$/', $normalized) === 1 ? $normalized : null;
	}

	private static function userCode(): string {
		$code = '';
		for ($i = 0; $i < 8; $i++) {
			$code .= self::USER_CODE_ALPHABET[random_int(0, strlen(self::USER_CODE_ALPHABET) - 1)];
		}
		return $code;
	}

	private static function base64Url(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}
}
