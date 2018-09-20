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

use daita\Traits\TArrayTools;
use daita\Traits\TNCDataResponse;
use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Service\ActivityStreamsService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\ServiceAccountsService;
use OCA\Social\Service\ServicesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;


class ActivityStreamsController extends Controller {

	use TArrayTools;
	use TNCDataResponse;

	/** @var string */
	private $userId;

	/** @var ConfigService */
	private $configService;

	/** @var ServiceAccountsService */
	private $serviceAccountsService;

	/** @var ActivityStreamsService */
	private $activityStreamsService;

	/** @var MiscService */
	private $miscService;


	/**
	 * ServiceAccountsController constructor.
	 *
	 * @param IRequest $request
	 * @param string $userId
	 * @param ConfigService $configService
	 * @param ServiceAccountsService $serviceAccountsService
	 * @param ActivityStreamsService $activityStreamsService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IRequest $request, string $userId, ConfigService $configService,
		ServiceAccountsService $serviceAccountsService,
		ActivityStreamsService $activityStreamsService, MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->userId = $userId;
		$this->configService = $configService;
		$this->serviceAccountsService = $serviceAccountsService;
		$this->activityStreamsService = $activityStreamsService;
		$this->miscService = $miscService;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $accountId
	 *
	 * @return DataResponse
	 */
	public function test(int $accountId): DataResponse {
		try {
			$account = $this->serviceAccountsService->getAccount($this->userId, $accountId);

			$result = $this->activityStreamsService->test($account);

			return $this->success($result);
		} catch (Exception $e) {
			return $this->fail($e->getMessage());
		}
	}


}

