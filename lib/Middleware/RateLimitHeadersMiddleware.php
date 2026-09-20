<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Middleware;

use OCA\Social\Service\RateLimitService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
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
 * client should be able to tell them apart. The same rule decides what happens
 * on an instance with no memcache, where there is nowhere to keep a counter:
 * the budget is published and the spending is not.
 */
class RateLimitHeadersMiddleware extends Middleware {
	public function __construct(
		private IUserSession $userSession,
		private RateLimitService $rateLimitService,
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
			$route = $this->budgetOf($controller, $methodName);
			if ($route === null) {
				return $response;
			}

			[$bucket, $limit, $period] = $route;
			$response->addHeader('X-RateLimit-Limit', (string)$limit);

			// An instance with no memcache configured has nowhere to keep a
			// counter, and `createDistributed()` hands back a cache that
			// stores nothing — so the count would come back 1 on every request
			// and the header would report "29 left" for ever. Found on devel,
			// which has no memcache: two posts in a row both said 29.
			//
			// A number that was never measured is worse than no number, so the
			// budget is still published and the spending is not. A client
			// reading `Limit` without `Remaining` knows what the budget is and
			// paces itself by counting its own requests, which is what it did
			// before any of these headers existed.
			// the app's own default is counted by the middleware that enforces
			// it, and reading that count rather than keeping a second one is
			// what stops a client being told it has budget left and then
			// refused for spending it
			$used = ($bucket === RateLimitService::BUCKET_API)
				? $this->rateLimitService->used($bucket, $period)
				: $this->rateLimitService->count($bucket, $period);
			if ($used === null) {
				return $response;
			}

			$response->addHeader('X-RateLimit-Remaining', (string)max(0, $limit - $used));
			// seconds since the epoch, as Mastodon sends it: the moment the
			// window this request fell in runs out
			$response->addHeader('X-RateLimit-Reset', (string)$this->rateLimitService->windowEnd($period));
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
	 * @return array{0: string, 1: int, 2: int}|null bucket, limit and period
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

				return [
					$controller::class . '/' . $methodName,
					$limit->getLimit(),
					$limit->getPeriod(),
				];
			}
		}

		// a route with no attribute is governed by the app's default, which is
		// a budget worth publishing rather than an unlimited one
		if (!$this->rateLimitService->appliesDefaultTo($controller, $methodName)) {
			return null;
		}

		$default = $this->rateLimitService->defaultBudget();

		return ($default === null)
			? null : [RateLimitService::BUCKET_API, $default[0], $default[1]];
	}
}
