<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 *
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Social\Controller;

use daita\MySmallPhpTools\Model\SimpleDataStore;
use daita\MySmallPhpTools\Traits\Nextcloud\TNCDataResponse;
use Exception;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\TestService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;


class ConfigController extends Controller {


	use TNCDataResponse;


	/** @var TestService */
	private $testService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	public function __construct(
		string $appName, IRequest $request, TestService $testService,
		ConfigService $configService, MiscService $miscService
	) {
		parent::__construct($appName, $request);

		$this->testService = $testService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}

	/**
	 * @param string $cloudAddress
	 *
	 * @return DataResponse
	 */
	public function setCloudAddress(string $cloudAddress): DataResponse {
		$this->configService->setCloudUrl($cloudAddress);

		return new DataResponse([]);
	}


	/**
	 * Local Version+Setup Test
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return DataResponse
	 */
	public function local(): DataResponse {
		$setup = false;
		try {
			$this->configService->getCloudUrl();
			$setup = true;
		} catch (SocialAppConfigException $e) {
		}

		return $this->success(
			[
				'version' => $this->configService->getAppValue('installed_version'),
				'setup'   => $setup
			]
		);
	}


	/**
	 * Actor Test
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $account
	 *
	 * @return DataResponse
	 */
	public function remote(string $account): DataResponse {
		if ($account === '' || $this->configService->getSystemValue('social.tests') === '') {
			return $this->local();
		}

		try {
			$this->configService->getCloudUrl();
		} catch (SocialAppConfigException $e) {
			return $this->success(['error' => 'error on my side: my own Social App is not configured']);
		}

		$tests = new SimpleDataStore(
			[
				'account'  => $account,
				'endpoint' => $this->configService->getSystemValue('social.tests')
			]
		);
		try {
			$this->testService->testWebfinger($tests);
		} catch (Exception $e) {
			return $this->fail($e, ['result' => $tests], Http::STATUS_OK);
		}

		return $this->success([$tests]);
	}

}

