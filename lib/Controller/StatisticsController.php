<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Service\AccountService;
use OCA\Social\Service\NetworkGrowthService;
use OCA\Social\Service\NetworkStatsService;
use OCA\Social\Service\StatisticsService;
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
 * The reader's own numbers.
 *
 * A session route rather than a client-API one, for the same reason the
 * migration routes are: it is for the person sitting in front of the browser,
 * and an account's whole history of engagement is not something a third-party
 * token should be handed. It is also only ever about the caller — there is no
 * account parameter, so there is nothing to point at somebody else.
 *
 * Rate limited because the answer is a walk over the account's posts rather
 * than a lookup, and a reload loop should not be able to spend that repeatedly.
 */
class StatisticsController extends Controller {
	public function __construct(
		IRequest $request,
		private ?string $userId,
		private AccountService $accountService,
		private StatisticsService $statisticsService,
		private NetworkStatsService $networkStatsService,
		private NetworkGrowthService $networkGrowthService,
		private LoggerInterface $logger,
	) {
		parent::__construct('social', $request);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statistics')]
	public function statistics(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$actor = $this->accountService->getActorFromUserId($this->userId);

			$statistics = $this->statisticsService->forAccount($actor);
			// the size of the place all this went out to, which nothing
			// counted here can say. Null when it is switched off or the
			// survey did not answer, and the page leaves the section out
			// rather than drawing zeros
			$statistics['network'] = $this->networkStatsService->network();
			// and whether that place is growing, which is the question a
			// single number cannot answer. A second source, named as such: the
			// snapshot above publishes no history at all
			$statistics['growth'] = $this->networkGrowthService->growth();
			// and what it is made of: "forty thousand servers" is an
			// abstraction, and the list of platforms is a picture of a place
			$statistics['software'] = $this->networkStatsService->software();

			return new DataResponse($statistics, Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->warning('could not build the statistics', [
				'userId' => $this->userId, 'exception' => $e,
			]);

			return new DataResponse(
				['error' => 'could not build the statistics'], Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}
}
