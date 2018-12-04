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


use OC\BackgroundJob\TimedJob;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\RequestException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\QueueService;
use OCP\AppFramework\QueryException;


/**
 * Class Queue
 *
 * @package OCA\Social\Cron
 */
class Queue extends TimedJob {


	/** @var ActivityService */
	private $activityService;

	/** @var QueueService */
	private $queueService;

	/** @var MiscService */
	private $miscService;


	/**
	 * Cache constructor.
	 */
	public function __construct() {
		$this->setInterval(12 * 60); // 12 minutes
	}


	/**
	 * @param mixed $argument
	 *
	 * @throws QueryException
	 */
	protected function run($argument) {
		$app = new Application();
		$c = $app->getContainer();

		$this->queueService = $c->query(QueueService::class);
		$this->activityService = $c->query(ActivityService::class);
		$this->miscService = $c->query(MiscService::class);

		$this->manageQueue();
	}


	private function manageQueue() {
		$requests = $this->queueService->getRequestStandby($total = 0);
		$this->activityService->manageInit();

		foreach ($requests as $request) {
			$request->setTimeout(ActivityService::TIMEOUT_SERVICE);
			try {
				$this->activityService->manageRequest($request);
			} catch (RequestException $e) {
			} catch (SocialAppConfigException $e) {
			}
		}

	}

}

