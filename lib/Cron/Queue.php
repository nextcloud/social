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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class Queue extends TimedJob {
	private ActivityService $activityService;
	private RequestQueueService $requestQueueService;
	private StreamQueueService $streamQueueService;
	private LoggerInterface $logger;

	public function __construct(
		ITimeFactory $time,
		RequestQueueService $requestQueueService,
		StreamQueueService $streamQueueService,
		ActivityService $activityService,
		LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(12 * 60);
		$this->requestQueueService = $requestQueueService;
		$this->streamQueueService = $streamQueueService;
		$this->activityService = $activityService;
		$this->logger = $logger;
	}

	protected function run($argument) {
		$this->manageRequestQueue();
		$this->manageStreamQueue();
	}

	private function manageRequestQueue() {
		// Re-queue anything a dead worker left stranded mid-delivery before draining.
		$this->requestQueueService->reapStaleRunning();

		$requests = $this->requestQueueService->getRequestStandby();
		$this->activityService->manageInit();

		foreach ($requests as $request) {
			$request->setTimeout(ActivityService::TIMEOUT_SERVICE);
			try {
				$this->activityService->manageRequest($request);
			} catch (SocialAppConfigException $e) {
				// The app is misconfigured, so *every* delivery in this queue
				// will fail the same way. That used to be swallowed silently:
				// at warning, because federation is down until it is fixed.
				$this->logger->warning(
					'[Cron\\Queue] cannot deliver ' . $request->getToken()
					. ': the Social app is not configured (' . $e->getMessage() . ')',
					['exception' => $e, 'token' => $request->getToken()]
				);
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
