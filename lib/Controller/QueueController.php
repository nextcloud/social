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
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
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
	 * The whole worker's time budget. Whatever is left over stays STANDBY and is
	 * delivered by the cron, so a post with many recipient inboxes (or a slow
	 * remote) cannot pin a PHP worker indefinitely.
	 */
	public const MAX_DURATION = 90;

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function asyncForRequest(string $token): Response {
		$requests = $this->requestQueueService->getRequestFromToken($token, RequestQueue::STATUS_STANDBY);

		if (empty($requests)) {
			return new DataResponse([], Http::STATUS_OK);
		}

		// From here the request is detached: async() has flushed an empty body and
		// closed the connection, and this worker only delivers queued activities.
		$this->async();

		$deadline = time() + self::MAX_DURATION;
		$this->activityService->manageInit();
		foreach ($requests as $request) {
			if (time() >= $deadline) {
				break;
			}
			$request->setTimeout(ActivityService::TIMEOUT_ASYNC);
			try {
				$this->activityService->manageRequest($request);
			} catch (SocialAppConfigException $e) {
			}
		}

		// exit(), not a Response: the connection is gone and headers are sent, so
		// letting the framework render a response would only feed warnings into the
		// log. Registered shutdown handlers still run.
		exit();
	}
}
