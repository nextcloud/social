<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Discovery;

use OCA\Social\Service\DurableCache;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/** Shared hourly ceiling for optional outbound post-discovery requests. */
class RemotePostDiscoveryBudget {
	public const WINDOW_SECONDS = 3600;
	public const MAX_REQUESTS_PER_HOUR = 120;
	private const CACHE_NAMESPACE = 'social.post-discovery.budget';
	private ?int $exhaustedBucket = null;

	public function __construct(
		private DurableCache $cache,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reserve one outbound discovery request across all workers and providers.
	 *
	 * A fixed hourly bucket makes the cap understandable to operators. Each
	 * outbound request claims a unique shared slot using the database table's
	 * unique key, so local APCu and concurrent workers cannot spend the same
	 * slot. Expired slots are reclaimed without deleting a live concurrent
	 * claim.
	 * If the budget store itself fails, discovery fails closed while local and
	 * cached candidates remain available.
	 */
	public function reserve(): bool {
		$now = $this->timeFactory->getTime();
		$bucket = intdiv($now, self::WINDOW_SECONDS);
		if ($this->exhaustedBucket === $bucket) {
			return false;
		}
		$ttl = max(1, self::WINDOW_SECONDS - ($now % self::WINDOW_SECONDS));

		try {
			$firstSlot = random_int(0, self::MAX_REQUESTS_PER_HOUR - 1);
			for ($offset = 0; $offset < self::MAX_REQUESTS_PER_HOUR; $offset++) {
				$slot = ($firstSlot + $offset) % self::MAX_REQUESTS_PER_HOUR;
				if ($this->cache->addShared(self::CACHE_NAMESPACE, $bucket . '/' . $slot, 1, $ttl)) {
					return true;
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning('Remote post-discovery budget could not be checked; request skipped', ['exception' => $e]);

			return false;
		}

		$this->exhaustedBucket = $bucket;
		$this->logger->debug('Remote post-discovery hourly budget exhausted', ['limit' => self::MAX_REQUESTS_PER_HOUR]);

		return false;
	}
}
