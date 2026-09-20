<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Middleware;

use OCA\Social\Service\RateLimitService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;

/**
 * A budget for the routes nobody gave one to.
 *
 * The routes that fan out — search, the directories, the follow graph — carry
 * a `UserRateLimit` and Nextcloud enforces it. Almost none of the reads do,
 * and there are some three hundred of them: an unauthenticated caller could
 * page through every public timeline, every profile and every hashtag on the
 * instance as fast as the database would answer, and nothing anywhere counted.
 *
 * So a route with no limit of its own gets the app's default one, applied here
 * because that is the one place every request passes. A route that declares a
 * limit is left alone — Nextcloud already counts it, and a second limiter over
 * the top would refuse at a number neither of them publishes.
 *
 * **What is exempt, and why.** Three kinds of traffic are not a client pacing
 * itself and must not be measured as if they were:
 *
 *  - federation (`ActivityPubController`, `SocialPubController`): deliveries
 *    arrive in bursts from a handful of addresses, and one busy peer would
 *    exhaust an address budget for every other peer behind the same relay.
 *    The inbox has a limiter of its own — `inbox_throttle`, counted per
 *    origin host, which is the unit that makes sense there.
 *  - bytes (avatars, attachments, emoji, GIFs): one public page is forty
 *    requests for pictures. Counting those against a page budget would refuse
 *    the page rather than slow the caller.
 *  - the internal async queue, which this server calls on itself.
 *
 * The default is generous on purpose. It exists to stop a scraper reading the
 * whole instance at machine speed, not to pace an app, and an instance behind
 * its own limiter can switch it off with `rate_limit_user`/`rate_limit_anon`.
 */
class ApiRateLimitMiddleware extends Middleware {
	public function __construct(
		private RateLimitService $rateLimitService,
	) {
	}

	#[\Override]
	public function beforeController(Controller $controller, string $methodName): void {
		if (!$this->rateLimitService->appliesDefaultTo($controller, $methodName)) {
			return;
		}

		$budget = $this->rateLimitService->defaultBudget();
		if ($budget === null) {
			return;
		}

		[$limit, $period] = $budget;
		// one bucket for the whole API rather than one per route: a caller
		// reading the instance is spending the same budget whichever route it
		// reads it through, and a per-route default would multiply the
		// allowance by the number of routes there happen to be
		$used = $this->rateLimitService->count(RateLimitService::BUCKET_API, $period);
		if ($used !== null && $used > $limit) {
			throw new RateLimitedException($limit, $period, $this->rateLimitService->windowEnd($period));
		}
	}

	#[\Override]
	public function afterException(
		Controller $controller, string $methodName, \Exception $exception,
	): Response {
		if (!($exception instanceof RateLimitedException)) {
			throw $exception;
		}

		$response = new JSONResponse(
			['error' => 'Too many requests. Try again later.'],
			Http::STATUS_TOO_MANY_REQUESTS
		);
		// what every Mastodon client reads to decide when to come back
		$response->addHeader('Retry-After', (string)max(1, $exception->getResetAt() - time()));
		$response->addHeader('X-RateLimit-Limit', (string)$exception->getLimit());
		$response->addHeader('X-RateLimit-Remaining', '0');
		$response->addHeader('X-RateLimit-Reset', (string)$exception->getResetAt());

		return $response;
	}
}
