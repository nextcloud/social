<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\StreamQueueService;
use OCP\AppFramework\QueryException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Class Queue
 *
 * @package OCA\Social\Cron
 */
class Queue extends TimedJob {
	private ActivityService $activityService;
	private RequestQueueService $requestQueueService;
	private StreamQueueService $streamQueueService;

	/**
	 * Cache constructor.
	 */
	public function __construct(ITimeFactory $time, RequestQueueService $requestQueueService, StreamQueueService $streamQueueService, ActivityService $activityService) {
		parent::__construct($time);
		$this->setInterval(12 * 60); // 12 minutes
		$this->requestQueueService = $requestQueueService;
		$this->streamQueueService = $streamQueueService;
		$this->activityService = $activityService;
	}


	/**
	 * @param mixed $argument
	 *
	 * @throws QueryException
	 */
	protected function run($argument) {
		$this->manageRequestQueue();
		$this->manageStreamQueue();
	}


	/**
	 */
	private function manageRequestQueue() {
		$requests = $this->requestQueueService->getRequestStandby();
		$this->activityService->manageInit();

		foreach ($requests as $request) {
			$request->setTimeout(ActivityService::TIMEOUT_SERVICE);
			try {
				$this->activityService->manageRequest($request);
			} catch (SocialAppConfigException $e) {
			}
		}
	}


	private function manageStreamQueue() {
		$total = 0;
		$items = $this->streamQueueService->getRequestStandby($total);

		foreach ($items as $item) {
			$this->streamQueueService->manageStreamQueue($item);
		}
	}
}
