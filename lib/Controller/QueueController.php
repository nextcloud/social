<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Tools\Traits\TAsync;
use OCP\AppFramework\Controller;
use OCP\IRequest;

/**
 * Class QueueController
 *
 * @package OCA\Social\Controller
 */
class QueueController extends Controller {
	use TAsync;

	private RequestQueueService $requestQueueService;
	private ActivityService $activityService;
	private MiscService $miscService;

	public function __construct(
		IRequest $request, RequestQueueService $requestQueueService, ActivityService $activityService,
		MiscService $miscService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->requestQueueService = $requestQueueService;
		$this->activityService = $activityService;
		$this->miscService = $miscService;
	}


	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function asyncForRequest(string $token) {
		$requests = $this->requestQueueService->getRequestFromToken($token, RequestQueue::STATUS_STANDBY);

		if (!empty($requests)) {
			$this->async();

			$this->activityService->manageInit();
			foreach ($requests as $request) {
				$request->setTimeout(ActivityService::TIMEOUT_ASYNC);
				try {
					$this->activityService->manageRequest($request);
				} catch (SocialAppConfigException $e) {
				}
			}
		}
		// or it will feed the logs.
		exit();
	}
}
