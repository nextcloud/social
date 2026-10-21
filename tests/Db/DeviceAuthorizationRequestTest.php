<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\DeviceAuthorizationRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeviceAuthorizationRequestTest extends TestCase {
	public static function pollOutcomes(): array {
		return [
			'pending' => ['pending', 2000, 5, 1000, 1005, ['error' => 'authorization_pending']],
			'slow down increases interval by five seconds' => ['pending', 2000, 5, 1000, 1004, ['error' => 'slow_down', 'interval' => 10]],
			'denied' => ['denied', 2000, 5, 1000, 1005, ['error' => 'access_denied']],
			'approved' => ['allowed', 2000, 5, 1000, 1005, ['ready' => true]],
			'issued cannot be used twice' => ['issued', 2000, 5, 1000, 1005, ['error' => 'invalid_grant']],
			'expired' => ['allowed', 1000, 5, 0, 1000, ['error' => 'expired_token']],
		];
	}

	#[DataProvider('pollOutcomes')]
	public function testPollOutcomes(
		string $status,
		int $expires,
		int $interval,
		int $lastPoll,
		int $now,
		array $expected,
	): void {
		self::assertSame($expected, DeviceAuthorizationRequest::pollOutcome($status, $expires, $interval, $lastPoll, $now));
	}
}
