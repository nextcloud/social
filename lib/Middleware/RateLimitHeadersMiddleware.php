<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Middleware;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUserSession;
use ReflectionMethod;
use Throwable;

/**
 * Tells a client how much of its budget is left.
 *
 * Every write route here carries a `UserRateLimit`, and Nextcloud enforces it:
 * past the limit the request is refused with a **429**, which is correct and is
 * not what was missing. What was missing is the three headers Mastodon sends
 * with *every* response — `X-RateLimit-Limit`, `-Remaining` and `-Reset` — so
 * a client can pace itself. Without them a client either hammers until it is
 * refused or backs off blindly, and every Mastodon client is written expecting
 * to read them.
 *
 * **The count here is advisory and the refusal is not.** Nextcloud's limiter
 * owns the enforcement and does not expose what it has counted, so this counts
 * the same requests in its own key: the same identity, the same route, the same
 * window. Both see every request through the same path, so they agree — and
 * where they cannot, the number that decides is Nextcloud's. A header that is
 * one out is a client pacing itself slightly early; a *limit* that was one out
 * would be a request refused for the wrong reason, which is why this does not
 * touch the refusal.
 *
 * A route with no rate-limit attribute gets no headers, rather than a made-up
 * budget: "unlimited" and "I did not measure" are different answers and a
 * client should be able to tell them apart.
 */
class RateLimitHeadersMiddleware extends Middleware {
	/** The cache the counters live in; distinct from anything else the app caches. */
	private const CACHE_PREFIX = 'social_ratelimit';

	public function __construct(
		private IRequest $request,
		private IUserSession $userSession,
		private ICacheFactory $cacheFactory,
	) {
	}

	/**
	 * @param Controller $controller
	 * @param string $methodName
	 * @param Response $response
	 */
	#[\Override]
	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		try {
			$budget = $this->budgetOf($controller, $methodName);
			if ($budget === null) {
				return $response;
			}

			[$limit, $period] = $budget;
			$used = $this->countThisRequest($controller, $methodName, $period);

			$response->addHeader('X-RateLimit-Limit', (string)$limit);
			$response->addHeader('X-RateLimit-Remaining', (string)max(0, $limit - $used));
			// seconds since the epoch, as Mastodon sends it: the moment the
			// window this request fell in runs out
			$response->addHeader('X-RateLimit-Reset', (string)$this->windowEnd($period));
		} catch (Throwable $e) {
			// a header is not worth failing a response over
		}

		return $response;
	}

	/**
	 * The limit and period this route carries, or null when it has none.
	 *
	 * `UserRateLimit` first: a signed-in caller is what almost every route
	 * here needs, and where both attributes are present Nextcloud applies the
	 * user one to a signed-in caller.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	private function budgetOf(Controller $controller, string $methodName): ?array {
		$method = new ReflectionMethod($controller, $methodName);

		$signedIn = $this->userSession->getUser() !== null;
		$order = $signedIn
			? [UserRateLimit::class, AnonRateLimit::class]
			: [AnonRateLimit::class, UserRateLimit::class];

		foreach ($order as $class) {
			foreach ($method->getAttributes($class) as $attribute) {
				$limit = $attribute->newInstance();

				return [$limit->getLimit(), $limit->getPeriod()];
			}
		}

		return null;
	}

	/**
	 * Records this request and says how many are now in the window.
	 *
	 * Keyed by the window's own start rather than by a sliding expiry, so the
	 * counter and the `Reset` header agree about where the window ends — a
	 * client told "0 remaining, resets in 4 seconds" and refused for another
	 * minute would have been told something untrue.
	 */
	private function countThisRequest(Controller $controller, string $methodName, int $period): int {
		$cache = $this->cacheFactory->createDistributed(self::CACHE_PREFIX);

		$key = implode('/', [
			$this->identity(),
			$controller::class,
			$methodName,
			// the window, so the count resets with it
			(string)intdiv(time(), max(1, $period)),
		]);

		$used = (int)$cache->get($key);
		$used++;
		$cache->set($key, $used, $period);

		return $used;
	}

	/** Who the budget belongs to: the signed-in account, else the address. */
	private function identity(): string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return 'u:' . $user->getUID();
		}

		return 'a:' . $this->request->getRemoteAddress();
	}

	private function windowEnd(int $period): int {
		$period = max(1, $period);

		return (intdiv(time(), $period) + 1) * $period;
	}
}
