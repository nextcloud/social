<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader\Jetstream;

use OCA\Social\Atproto\Reader\Jetstream\JetstreamState;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DurableCache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The listener's cursor and report live in the durable cache's table,
 * whatever memcache there is, and what an older version kept in app config
 * is moved there once.
 */
#[AllowMockObjectsWithoutExpectations]
class JetstreamStateTest extends TestCase {
	/** @var array<string, string> app values */
	private array $values = [];
	/** @var array<string, array{0: mixed, 1: int}> the table's rows, value and TTL, by namespace and key */
	private array $rows = [];
	/** @var ConfigService&MockObject */
	private ConfigService $configService;
	private JetstreamState $state;

	protected function setUp(): void {
		$this->configService = $this->createMock(ConfigService::class);
		$this->configService->method('getAppValue')->willReturnCallback(fn (string $key): string => $this->values[$key] ?? '');
		$this->configService->method('deleteAppValue')->willReturnCallback(function (string $key): void {
			unset($this->values[$key]);
		});
		$this->configService->expects($this->never())->method('setAppValue');
		$durableCache = $this->createMock(DurableCache::class);
		$durableCache->expects($this->never())->method('get');
		$durableCache->expects($this->never())->method('set');
		$durableCache->method('getShared')->willReturnCallback(fn (string $namespace, string $key): mixed => $this->rows[$namespace . '/' . $key][0] ?? null);
		$durableCache->method('setShared')->willReturnCallback(function (string $namespace, string $key, mixed $value, int $ttl): void {
			$this->rows[$namespace . '/' . $key] = [$value, $ttl];
		});
		$this->state = new JetstreamState($durableCache, $this->configService);
	}

	public function testTheCursorAndTheReportAreKeptInTheTableForLong(): void {
		$this->assertSame(0, $this->state->cursor());
		$this->assertNull($this->state->status());

		$this->state->keepCursor(1760000001000000);
		$this->state->report(['running' => true, 'seen' => 1760000001]);

		$this->assertSame(1760000001000000, $this->state->cursor());
		$this->assertSame(['running' => true, 'seen' => 1760000001], $this->state->status());
		foreach ($this->rows as [, $ttl]) {
			$this->assertSame(JetstreamState::TTL, $ttl);
		}
		$this->assertGreaterThanOrEqual(365 * 86400, JetstreamState::TTL, 'far longer than any listener is down');
	}

	public function testWhatAnOlderVersionKeptInAppConfigIsMovedOverOnce(): void {
		$this->values[ConfigService::ATPROTO_JETSTREAM_CURSOR] = '1760000000000000';
		$this->values[ConfigService::ATPROTO_JETSTREAM_STATUS] = '{"running":true,"seen":1760000000,"accounts":2}';

		$this->assertSame(1760000000000000, $this->state->cursor());
		$this->assertSame(['running' => true, 'seen' => 1760000000, 'accounts' => 2], $this->state->status());

		$this->assertSame([], $this->values, 'the app-config keys are gone');
		$this->assertSame(1760000000000000, $this->state->cursor(), 'read from the table from now on');
		$this->assertSame(2, $this->state->status()['accounts']);
	}

	public function testTheTableWinsOverALeftoverAppConfigValue(): void {
		$this->state->keepCursor(1760000005000000);
		$this->values[ConfigService::ATPROTO_JETSTREAM_CURSOR] = '1760000000000000';

		$this->assertSame(1760000005000000, $this->state->cursor());
	}
}
