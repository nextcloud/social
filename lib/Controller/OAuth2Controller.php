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


use daita\Traits\TNCDataResponse;
use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\ServiceAccountsService;
use OCP\AppFramework\Controller;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;

class OAuth2Controller extends Controller {


	use TNCDataResponse;


	/** @var IConfig */
	private $config;

	/** @var string */
	private $userId;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var ServiceAccountsService */
	private $serviceAccountsService;

	/** @var MiscService */
	private $miscService;


	/**
	 * NavigationController constructor.
	 *
	 * @param IRequest $request
	 * @param IConfig $config
	 * @param string $userId
	 * @param IURLGenerator $urlGenerator
	 * @param ServiceAccountsService $serviceAccountsService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IRequest $request, IConfig $config, string $userId, IURLGenerator $urlGenerator,
		ServiceAccountsService $serviceAccountsService, MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->config = $config;
		$this->userId = $userId;
		$this->urlGenerator = $urlGenerator;

		$this->serviceAccountsService = $serviceAccountsService;
		$this->miscService = $miscService;
	}


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @param int $serviceId
	 *
	 * @throws Exception
	 */
	public function setCode(int $serviceId) {

		$code = $_GET['code'];
		// TODO: verify $state
		$state = $_GET['state'];

		$this->serviceAccountsService->generateAccount($this->userId, $serviceId, $code);
	}


}
