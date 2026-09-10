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
 * fill the stream queue — and the verification itself fetches the signing key,
 * so a flood is expensive long before anything about it is known to be true.
 *
 * The primary bucket is the connecting address, which is the one thing about an
 * unauthenticated request the sender cannot choose. The host named in the
 * Signature header's keyId is unverified at this point, so mixing it into that
 * key would let a flood mint a fresh, empty bucket per request just by writing a
 * different hostname each time. It gets a second, wider bucket of its own
 * instead, which bounds what one claimed origin can send from however many
 * addresses.
 *
 * Fixed one-minute windows in the distributed cache; the counter is not atomic,
 * which is fine for a ceiling. A limit of 0 disables the check.
 */
class InboxLimiter {
	public const WINDOW = 60;

	/**
	 * The claimed-host bucket is deliberately looser than the per-address one:
	 * a large instance legitimately delivers from several addresses, and this
	 * ceiling exists only to bound one origin's total.
	 */
	public const HOST_LIMIT_FACTOR = 4;

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

		// the address first: it is the only part of this the sender is stuck with
		$this->consume('ip.' . md5($request->getRemoteAddress()), $window, $limit);

		$claimedHost = $this->claimedHost($request);
		if ($claimedHost !== '') {
			$this->consume(
				'host.' . md5($claimedHost), $window, $limit * self::HOST_LIMIT_FACTOR
			);
		}
	}

	/**
	 * @throws TooManyRequestsException
	 */
	private function consume(string $bucket, int $window, int $limit): void {
		$key = $bucket . '.' . $window;

		$count = (int)($this->cache->get($key) ?? 0);
		if ($count >= $limit) {
			throw new TooManyRequestsException('inbox rate limit exceeded');
		}

		$this->cache->set($key, $count + 1, self::WINDOW * 2);
	}

	/**
	 * The host of the keyId the sender claims to sign with — unverified, and so
	 * never the only thing a bucket is keyed on.
	 */
	private function claimedHost(IRequest $request): string {
		$signature = $request->getHeader('Signature');
		if ($signature !== '' && preg_match('/keyId="([^"]+)"/', $signature, $matches) === 1) {
			return strtolower(parse_url($matches[1], PHP_URL_HOST) ?: '');
		}

		return '';
	}
}
