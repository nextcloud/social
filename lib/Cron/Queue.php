<?php

declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
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
