<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader\Jetstream;

use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DurableCache;

/**
 * Where the Jetstream listener got to and its last report, kept in the
 * durable cache's table rather than app config: the listener writes them
 * every few seconds, and an app-config write invalidates the config cache
 * of every request on the host.
 *
 * The table, whatever memcache there is, since `occ social:atproto:listen`
 * writes them and a web request (the admin page) reads them. Every row of
 * it expires; a year is far longer than any listener is down, and each
 * write starts the year again. A value an older version kept in app config
 * is moved over on its first read.
 */
class JetstreamState {
	private const NAMESPACE = 'social.atproto.jetstream';
	private const CURSOR = 'cursor';
	private const STATUS = 'status';
	public const TTL = 366 * 86400;

	public function __construct(
		private DurableCache $durableCache,
		private ConfigService $configService,
	) {
	}

	/** the `time_us` of the last event kept, 0 for none */
	public function cursor(): int {
		return (int)($this->read(self::CURSOR, ConfigService::ATPROTO_JETSTREAM_CURSOR) ?? 0);
	}

	public function keepCursor(int $cursor): void {
		$this->durableCache->setShared(self::NAMESPACE, self::CURSOR, $cursor, self::TTL);
	}

	/**
	 * The listener's last report as it wrote it, null for none.
	 */
	public function status(): ?array {
		$status = $this->read(self::STATUS, ConfigService::ATPROTO_JETSTREAM_STATUS);

		return is_array($status) ? $status : null;
	}

	public function report(array $status): void {
		$this->durableCache->setShared(self::NAMESPACE, self::STATUS, $status, self::TTL);
	}

	private function read(string $key, string $appConfigKey): mixed {
		$value = $this->durableCache->getShared(self::NAMESPACE, $key);
		if ($value !== null) {
			return $value;
		}
		$kept = (string)$this->configService->getAppValue($appConfigKey);
		if ($kept === '') {
			return null;
		}
		$value = $key === self::STATUS ? json_decode($kept, true) : (int)$kept;
		if ($value !== null) {
			$this->durableCache->setShared(self::NAMESPACE, $key, $value, self::TTL);
		}
		$this->configService->deleteAppValue($appConfigKey);

		return $value;
	}
}
