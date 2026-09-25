<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\HostBreakerRequest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The delivery breaker's table, against the real database: the update-then-
 * insert of `open()` on every platform, and the reads the drain relies on.
 */
class HostBreakerRequestTest extends TestCase {
	private const HOST = 'itest-breaker.example';

	private HostBreakerRequest $breaker;

	protected function setUp(): void {
		parent::setUp();
		$this->breaker = Server::get(HostBreakerRequest::class);
		$this->breaker->close(self::HOST);
	}

	protected function tearDown(): void {
		$this->breaker->close(self::HOST);
		parent::tearDown();
	}

	public function testAFailureIsRecordedAndReadBack(): void {
		$now = time();
		$this->breaker->open(self::HOST, 1, $now + 60, $now);
		$this->breaker->open(self::HOST, 2, $now + 120, $now);

		$state = $this->breaker->failingSince($now - 3600)[self::HOST] ?? null;

		$this->assertSame(['strikes' => 2, 'open_until' => $now + 120, 'last_failure' => $now], $state);
	}

	public function testAnAnswerClearsTheHost(): void {
		$this->breaker->open(self::HOST, 1, time() + 60, time());
		$this->breaker->close(self::HOST);

		$this->assertArrayNotHasKey(self::HOST, $this->breaker->failingSince(0));
	}

	public function testOldFailuresAreForgotten(): void {
		$this->breaker->open(self::HOST, 3, time() - 7000, time() - 7200);

		$this->assertArrayNotHasKey(self::HOST, $this->breaker->failingSince(time() - 3600));
		$this->assertGreaterThanOrEqual(1, $this->breaker->forgetBefore(time() - 3600));
		$this->assertArrayNotHasKey(self::HOST, $this->breaker->failingSince(0));
	}
}
