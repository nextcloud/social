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
 * The bucket checked before anything is known is the connecting address, which
 * is the one thing about an unauthenticated request the sender cannot choose.
 * The host named in the Signature header's keyId cannot be a bucket of its own
 * there: nothing has checked that the sender has anything to do with it, so
 * counting a request against the host it *names* lets any four cheap addresses
 * fill a large instance's bucket every minute and cut this server off from it —
 * the deliveries that are genuinely from it are then answered 429 and the local
 * timelines simply go quiet. That is a better attack than the one the bucket
 * was there to stop.
 *
 * A per-origin ceiling is still worth having, so it moved behind the signature:
 * assertOriginAllowed() is called with the origin the HTTP signature actually
 * proved, and only a peer that can sign for a host can spend that host's
 * budget.
 *
 * Fixed one-minute windows in the distributed cache; the counter is not atomic,
 * which is fine for a ceiling. A limit of 0 disables the check.
 */
class InboxLimiter {
	public const WINDOW = 60;

	/**
	 * The per-origin bucket is deliberately looser than the per-address one: a
	 * large instance legitimately delivers from several addresses, and this
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
	 * The ceiling that applies before anything about the request is known: the
	 * source address, and nothing else the sender wrote.
	 *
	 * @throws TooManyRequestsException
	 */
	public function assertAllowed(IRequest $request): void {
		$limit = $this->limit();
		if ($limit <= 0) {
			return;
		}

		$this->consume('ip.' . md5($request->getRemoteAddress()), $this->window(), $limit);
	}

	/**
	 * The ceiling on one origin's total, across however many addresses it
	 * delivers from.
	 *
	 * $origin must be the host the HTTP signature verified against — the keyId
	 * as it arrived proves nothing, and spending a bucket on an unproven name
	 * is a way to silence the instance that owns it.
	 *
	 * @throws TooManyRequestsException
	 */
	public function assertOriginAllowed(string $origin): void {
		$origin = strtolower(trim($origin));
		$limit = $this->limit();
		if ($limit <= 0 || $origin === '') {
			return;
		}

		$this->consume(
			'host.' . md5($origin), $this->window(), $limit * self::HOST_LIMIT_FACTOR
		);
	}

	private function limit(): int {
		return (int)$this->configService->getAppValue(ConfigService::SOCIAL_INBOX_THROTTLE);
	}

	private function window(): int {
		return (int)floor(time() / self::WINDOW);
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
}
