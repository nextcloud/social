<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\StreamQueueService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

class Queue extends TimedJob {
	/**
	 * How long one cron run may spend draining, in seconds.
	 *
	 * QueueController gives its drain a budget because an HTTP request has to
	 * return. This job had the same problem with a longer fuse and no budget at
	 * all: it ran until the batch was done or something killed it, so a backlog
	 * of slow or unreachable inboxes could hold a cron slot open indefinitely
	 * and overlap the next run. Rows left behind stay in standby and are picked
	 * up by the following run, which is what the queue is for.
	 *
	 * Well inside the 12 minute interval below, so two runs cannot overlap.
	 */
	public const MAX_DURATION = 300;

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

	#[\Override]
	protected function run($argument) {
		$deadline = time() + self::MAX_DURATION;

		$this->manageRequestQueue($deadline);
		$this->manageStreamQueue($deadline);
	}

	private function manageRequestQueue(int $deadline) {
		// Re-queue anything a dead worker left stranded mid-delivery before draining.
		$this->requestQueueService->reapStaleRunning();

		$requests = $this->requestQueueService->getRequestStandby();
		$this->activityService->manageInit();

		foreach ($requests as $request) {
			if (time() >= $deadline) {
				break;
			}

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
				$this->releaseRequest($request);
			} catch (Throwable $e) {
				// One row must cost that row and no more. manageRequest() ends
				// the failures it knows about, but a corrupt signing key or the
				// database going away comes out of it unhandled — and used to
				// take the rest of the batch with it.
				$this->logger->warning(
					'[Cron\\Queue] delivery of ' . $request->getToken() . ' failed: '
					. get_class($e) . ' ' . $e->getMessage(),
					['exception' => $e, 'token' => $request->getToken()]
				);
				$this->releaseRequest($request);
			}
		}
	}

	/**
	 * Hand a row back to the queue after an attempt that ended outside
	 * ActivityService's own error handling.
	 *
	 * It was marked `running` before the attempt, and left that way it is never
	 * retried, never counted against MAX_TRIES, and only freed by the stale
	 * reaper an hour later — for an activity nobody will ever see delivered.
	 */
	private function releaseRequest(RequestQueue $request): void {
		try {
			$this->requestQueueService->endRequest($request, false);
		} catch (Throwable $e) {
			// the database is what just failed; the stale reaper is the backstop
			$this->logger->warning(
				'[Cron\\Queue] cannot return ' . $request->getToken() . ' to standby',
				['exception' => $e, 'token' => $request->getToken()]
			);
		}
	}

	private function manageStreamQueue(int $deadline) {
		$total = 0;
		$items = $this->streamQueueService->getRequestStandby($total);

		foreach ($items as $item) {
			if (time() >= $deadline) {
				break;
			}

			$this->streamQueueService->manageStreamQueue($item);
		}
	}
}
