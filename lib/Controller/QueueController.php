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

namespace OCA\Social\Controller;


use daita\MySmallPhpTools\Traits\TAsync;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\RequestQueueService;
use OCP\AppFramework\Controller;
use OCP\IRequest;


/**
 * Class QueueController
 *
 * @package OCA\Social\Controller
 */
class QueueController extends Controller {


	use TAsync;

	/** @var RequestQueueService */
	private $requestQueueService;

	/** @var ActivityService */
	private $activityService;

	/** @var MiscService */
	private $miscService;


	/**
	 * QueueController constructor.
	 *
	 * @param IRequest $request
	 * @param RequestQueueService $requestQueueService
	 * @param ActivityService $activityService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IRequest $request, RequestQueueService $requestQueueService, ActivityService $activityService,
		MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->requestQueueService = $requestQueueService;
		$this->activityService = $activityService;
		$this->miscService = $miscService;
	}


	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $token
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

