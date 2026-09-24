<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use InvalidArgumentException;
use OCA\Social\AppInfo\Application;
use OCA\Social\Service\SubscriptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The feeds an account follows.
 *
 * Not a Mastodon API — Mastodon has no such thing — so these are this app's
 * own routes, session-authenticated like the rest of the pages rather than
 * bearer-token like the client API.
 */
class SubscriptionsController extends Controller {
	public function __construct(
		IRequest $request,
		private ?string $userId,
		private SubscriptionService $subscriptionService,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/subscriptions')]
	public function index(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse(['feeds' => $this->subscriptionService->feeds($this->userId)]);
	}

	/**
	 * Follows a feed, a page that declares one, or a YouTube channel.
	 *
	 * Rate-limited because following resolves an address somebody typed, which
	 * is one or two requests out to a server they named.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/subscriptions')]
	public function follow(string $url = ''): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new DataResponse($this->subscriptionService->follow($this->userId, $url), Http::STATUS_OK);
		} catch (InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->warning('following a feed failed', ['url' => $url, 'exception' => $e]);

			return new DataResponse(['error' => 'that could not be followed'], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/subscriptions/{id}', requirements: ['id' => '\\d+'])]
	public function unfollow(int $id): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->subscriptionService->unfollow($this->userId, $id)) {
			return new DataResponse(['error' => 'no such subscription'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse([]);
	}

	/**
	 * What those feeds have published, newest first.
	 *
	 * Paged on the row id: two feeds read in the same minute give a dozen
	 * entries the same date to the second, and a cursor on the date either
	 * loops on them or steps over the rest.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/subscriptions/timeline')]
	public function timeline(int $limit = 40, int $max_id = 0): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse([
			'items' => $this->subscriptionService->timeline($this->userId, $limit, $max_id),
		]);
	}

	/** Follows every channel in a YouTube takeout's `subscriptions.csv`. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 4, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/subscriptions/takeout')]
	public function takeout(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$file = $_FILES['file'] ?? [];
		if ($file === [] || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return new DataResponse(['error' => 'no file was uploaded'], Http::STATUS_BAD_REQUEST);
		}

		$csv = file_get_contents($file['tmp_name']);
		if ($csv === false) {
			return new DataResponse(['error' => 'that file could not be read'], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new DataResponse(
				['subscribed' => $this->subscriptionService->importTakeout($this->userId, $csv)],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			$this->logger->warning('importing a takeout failed', ['exception' => $e]);

			return new DataResponse(['error' => 'that file could not be read'], Http::STATUS_BAD_REQUEST);
		}
	}
}
