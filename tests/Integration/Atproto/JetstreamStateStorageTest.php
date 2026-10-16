<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Atproto;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Reader\Jetstream\JetstreamState;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DurableCache;
use OCP\IAppConfig;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The Jetstream listener's cursor and report against the real app config
 * and `social_durable_cache`: what an older version left in app config is
 * moved to the table and its key deleted, and from then on both are read
 * from and written to the table only.
 */
class JetstreamStateStorageTest extends TestCase {
	private const NAMESPACE = 'social.atproto.jetstream';

	private IAppConfig $appConfig;

	protected function setUp(): void {
		$this->appConfig = Server::get(IAppConfig::class);
		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testAnAppConfigValueIsMovedToTheTableOnce(): void {
		$this->appConfig->setValueString(Application::APP_ID, ConfigService::ATPROTO_JETSTREAM_CURSOR, '1760000000000000');
		$this->appConfig->setValueString(Application::APP_ID, ConfigService::ATPROTO_JETSTREAM_STATUS, '{"running":true,"seen":1760000000,"accounts":3}');
		$state = Server::get(JetstreamState::class);

		$this->assertSame(1760000000000000, $state->cursor());
		$this->assertSame(3, $state->status()['accounts'] ?? null);

		$this->assertFalse($this->appConfig->hasKey(Application::APP_ID, ConfigService::ATPROTO_JETSTREAM_CURSOR));
		$this->assertFalse($this->appConfig->hasKey(Application::APP_ID, ConfigService::ATPROTO_JETSTREAM_STATUS));
		$this->assertSame(1760000000000000, Server::get(DurableCache::class)->getShared(self::NAMESPACE, 'cursor'));
	}

	public function testTheCursorAndTheReportRoundTripThroughTheTable(): void {
		$state = Server::get(JetstreamState::class);

		$state->keepCursor(1760000001234567);
		$state->report(['running' => false, 'seen' => 1760000001, 'accounts' => 0]);

		$fresh = new JetstreamState(Server::get(DurableCache::class), Server::get(ConfigService::class));
		$this->assertSame(1760000001234567, $fresh->cursor());
		$this->assertSame(['running' => false, 'seen' => 1760000001, 'accounts' => 0], $fresh->status());
		$this->assertFalse($this->appConfig->hasKey(Application::APP_ID, ConfigService::ATPROTO_JETSTREAM_CURSOR), 'nothing is written to app config');
	}

	private function cleanUp(): void {
		$cache = Server::get(DurableCache::class);
		$cache->removeShared(self::NAMESPACE, 'cursor');
		$cache->removeShared(self::NAMESPACE, 'status');
		$this->appConfig->deleteKey(Application::APP_ID, ConfigService::ATPROTO_JETSTREAM_CURSOR);
		$this->appConfig->deleteKey(Application::APP_ID, ConfigService::ATPROTO_JETSTREAM_STATUS);
	}
}
