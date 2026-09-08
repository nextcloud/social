<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\TestService;
use OCA\Social\Tools\Model\SimpleDataStore;
use OCA\Social\Tools\Traits\TNCDataResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ConfigController extends Controller {
	use TNCDataResponse;

	private TestService $testService;
	private ConfigService $configService;
	private MiscService $miscService;

	public function __construct(
		string $appName, IRequest $request, TestService $testService,
		ConfigService $configService, MiscService $miscService,
	) {
		parent::__construct($appName, $request);

		$this->testService = $testService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}

	public function setCloudAddress(string $cloudAddress): DataResponse {
		$this->configService->setCloudUrl($cloudAddress);

		return new DataResponse([]);
	}


	/**
	 * Local Version+Setup Test
	 *
	 * @NoCSRFRequired
	 * @PublicPage
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
				'setup' => $setup
			]
		);
	}


	/**
	 * Actor Test
	 *
	 * @NoCSRFRequired
	 * @PublicPage
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
				'account' => $account,
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
