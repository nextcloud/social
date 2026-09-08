<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\TooManyRequestsException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;

/**
 * A cheap per-origin ceiling on incoming inbox deliveries, applied before the
 * (expensive) signature verification: signatures and replay windows prove
 * authenticity, but nothing else caps how fast a valid-but-hostile peer can
 * fill the stream queue.
 *
 * The bucket key is the host claimed in the Signature header's keyId plus the
 * connecting address, so a flood cannot dodge the limit by claiming someone
 * else's host, and a busy shared IP (NAT) is not punished for a single noisy
 * origin. Fixed one-minute windows in the distributed cache; the counter is
 * not atomic, which is fine for a ceiling. A limit of 0 disables the check.
 */
class InboxLimiter {
	public const WINDOW = 60;

	private ICache $cache;

	public function __construct(
		ICacheFactory $cacheFactory,
		private ConfigService $configService,
	) {
		$this->cache = $cacheFactory->createDistributed('social.inbox');
	}

	/**
	 * @throws TooManyRequestsException
	 */
	public function assertAllowed(IRequest $request): void {
		$limit = (int)$this->configService->getAppValue(ConfigService::SOCIAL_INBOX_THROTTLE);
		if ($limit <= 0) {
			return;
		}

		$window = (int)floor(time() / self::WINDOW);
		$key = md5($this->claimedHost($request) . '|' . $request->getRemoteAddress()) . '.' . $window;

		$count = (int)($this->cache->get($key) ?? 0);
		if ($count >= $limit) {
			throw new TooManyRequestsException('inbox rate limit exceeded');
		}

		$this->cache->set($key, $count + 1, self::WINDOW * 2);
	}

	/**
	 * The host of the keyId the sender claims to sign with. Unverified at this
	 * point — that is fine, it only picks the bucket: lying about the host
	 * moves the flood into the liar's own (host, ip) bucket, not out of it.
	 */
	private function claimedHost(IRequest $request): string {
		$signature = $request->getHeader('Signature');
		if ($signature !== '' && preg_match('/keyId="([^"]+)"/', $signature, $matches) === 1) {
			return strtolower(parse_url($matches[1], PHP_URL_HOST) ?: '');
		}

		return '';
	}
}
