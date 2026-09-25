<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\DurableCacheRequest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * `social_durable_cache` against the real table: the statements the unit
 * suite can only stand in for. A read ignores an expired row, a write
 * replaces, an insert skips a key already present without raising — which on
 * PostgreSQL is the difference between a counter and an aborted transaction —
 * and the purge takes only what has expired.
 */
class DurableCacheTableTest extends TestCase {
	private const KEY = 'integration-test-durable-cache-000000000000000000000000000000001';
	private const OTHER = 'integration-test-durable-cache-000000000000000000000000000000002';
	private const NOW = 1_760_000_000;

	private DurableCacheRequest $request;

	protected function setUp(): void {
		parent::setUp();
		$this->request = Server::get(DurableCacheRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->request->delete(self::KEY);
		$this->request->delete(self::OTHER);
	}

	public function testAWrittenValueIsReadUntilItExpires(): void {
		$this->request->write(self::KEY, '"value"', self::NOW + 60);

		$this->assertSame('"value"', $this->request->read(self::KEY, self::NOW));
		$this->assertNull($this->request->read(self::KEY, self::NOW + 60));
	}

	public function testASecondWriteReplacesTheFirst(): void {
		$this->request->write(self::KEY, '1', self::NOW + 60);
		$this->request->write(self::KEY, '2', self::NOW + 120);

		$this->assertSame('2', $this->request->read(self::KEY, self::NOW + 90));
	}

	public function testAnInsertOverAnExistingKeyIsSkippedRatherThanRaised(): void {
		$this->assertTrue($this->request->insertIfAbsent(self::KEY, '1', self::NOW + 60));
		$this->assertFalse($this->request->insertIfAbsent(self::KEY, '5', self::NOW + 60));

		$this->assertSame('1', $this->request->read(self::KEY, self::NOW));
	}

	public function testReplacingAValueLeavesItsExpiryAndSkipsAnExpiredRow(): void {
		$this->request->write(self::KEY, '1', self::NOW + 60);

		$this->assertTrue($this->request->replaceValue(self::KEY, '2', self::NOW));
		$this->assertNull($this->request->read(self::KEY, self::NOW + 60));
		$this->assertFalse($this->request->replaceValue(self::KEY, '3', self::NOW + 60));
	}

	public function testThePurgeTakesOnlyWhatHasExpired(): void {
		$this->request->write(self::KEY, '1', self::NOW + 10);
		$this->request->write(self::OTHER, '1', self::NOW + 100);

		$this->assertGreaterThanOrEqual(1, $this->request->purge(self::NOW + 50));
		$this->assertTrue($this->request->insertIfAbsent(self::KEY, '1', self::NOW + 100));
		$this->assertSame('1', $this->request->read(self::OTHER, self::NOW + 50));
	}
}
