<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Service\AccountService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\MemoriesService;
use OCA\Social\Service\PlaceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IDateTimeZone;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What the reader wrote on this day in years gone by.
 *
 * A session route rather than a client-API one, for the reason the statistics
 * are: it is for the person sitting in front of the browser, and it answers
 * with their followers-only and direct posts as well as their public ones —
 * which is exactly what a third-party token should not be handed.
 *
 * There is no account parameter, so there is nothing to point at somebody
 * else. That is the whole of the access control here, and it is why it has to
 * stay that way: the query behind it does not filter by audience, because it
 * is only ever asked about the caller.
 */
class MemoriesController extends Controller {
	public function __construct(
		IRequest $request,
		private ?string $userId,
		private AccountService $accountService,
		private MemoriesService $memoriesService,
		private LinkPreviewService $linkPreviewService,
		private PlaceService $placeService,
		private IDateTimeZone $dateTimeZone,
		private LoggerInterface $logger,
	) {
		parent::__construct('social', $request);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/memories/on_this_day')]
	public function onThisDay(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$actor = $this->accountService->getActorFromUserId($this->userId);
			$posts = $this->memoriesService->onThisDay($actor, $this->dateTimeZone->getTimeZone());

			// the same two the timeline attaches; neither is part of the
			// stored post, and a memory is drawn by the same card
			$this->linkPreviewService->attachCards($posts);
			$this->placeService->attachPlaces($posts);

			return new DataResponse($posts, Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->warning('could not read the memories', [
				'userId' => $this->userId, 'exception' => $e,
			]);

			return new DataResponse(
				['error' => 'could not read the memories'], Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}
}
