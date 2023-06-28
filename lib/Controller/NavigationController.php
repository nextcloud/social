<?php

declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Jonas Sulzer <jonas@violoncello.ch>
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

use Exception;
use OC\User\NoUserException;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\AccountAlreadyExistsException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\MiscService;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TNCDataResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IInitialStateService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Server;

/**
 * Class NavigationController
 *
 * @package OCA\Social\Controller
 */
class NavigationController extends Controller {
	use TArrayTools;
	use TNCDataResponse;

	private ?string $userId = null;
	private IConfig $config;
	private IURLGenerator $urlGenerator;
	private AccountService $accountService;
	private DocumentService $documentService;
	private ConfigService $configService;
	private MiscService $miscService;
	private IL10N $l10n;
	private CheckService $checkService;
	private IInitialStateService $initialStateService;

	public function __construct(
		IL10N $l10n,
		IRequest $request,
		?string $userId,
		IConfig $config,
		IInitialStateService $initialStateService,
		IURLGenerator $urlGenerator,
		AccountService $accountService,
		DocumentService $documentService,
		ConfigService $configService,
		CheckService $checkService,
		MiscService $miscService
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->userId = $userId;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->initialStateService = $initialStateService;

		$this->urlGenerator = $urlGenerator;
		$this->checkService = $checkService;
		$this->accountService = $accountService;
		$this->documentService = $documentService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * Display the navigation page of the Social app.
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 *
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	public function navigate(string $path = ''): TemplateResponse {
		$serverData = [
			'public' => false,
			'firstrun' => false,
			'setup' => false,
			'isAdmin' => Server::get(IGroupManager::class)
									 ->isAdmin($this->userId),
			'cliUrl' => $this->getCliUrl()
		];

		try {
			$serverData['cloudAddress'] = $this->configService->getCloudUrl();
		} catch (SocialAppConfigException $e) {
			$this->checkService->checkInstallationStatus(true);
			$cloudAddress = $this->setupCloudAddress();
			if ($cloudAddress !== '') {
				$serverData['cloudAddress'] = $cloudAddress;
			} else {
				$serverData['setup'] = true;

				if ($serverData['isAdmin']) {
					$cloudAddress = $this->request->getParam('cloudAddress');
					if ($cloudAddress !== null) {
						$this->configService->setCloudUrl($cloudAddress);
					} else {
						$this->initialStateService->provideInitialState(Application::APP_ID, 'serverData', $serverData);
						return new TemplateResponse(Application::APP_ID, 'main');
					}
				}
			}
		}

		try {
			$this->configService->getSocialUrl();
		} catch (SocialAppConfigException $e) {
			$this->configService->setSocialUrl();
		}

		/*
		 * Create social user account if it doesn't exist yet
		 */
		try {
			$this->accountService->createActor($this->userId, $this->userId);
			$serverData['firstrun'] = true;
		} catch (AccountAlreadyExistsException $e) {
			// we do nothing
		} catch (NoUserException $e) {
			// well, should not happens
		} catch (SocialAppConfigException $e) {
			// neither.
		}

		if ($serverData['isAdmin']) {
			$checks = $this->checkService->checkDefault();
			$serverData['checks'] = $checks;
		}

		$this->initialStateService->provideInitialState(Application::APP_ID, 'serverData', $serverData);
		return new TemplateResponse(Application::APP_ID, 'main');
	}

	private function setupCloudAddress(): string {
		$frontControllerActive =
			($this->config->getSystemValue('htaccess.IgnoreFrontController', false) === true
			 || getenv('front_controller_active') === 'true');

		$cloudAddress = rtrim($this->config->getSystemValue('overwrite.cli.url', ''), '/');
		if ($cloudAddress !== '') {
			if (!$frontControllerActive) {
				$cloudAddress .= '/index.php';
			}
			$this->configService->setCloudUrl($cloudAddress);

			return $cloudAddress;
		}

		return '';
	}

	private function getCliUrl() {
		$url = rtrim($this->urlGenerator->getBaseUrl(), '/');
		$frontControllerActive =
			($this->config->getSystemValue('htaccess.IgnoreFrontController', false) === true
			 || getenv('front_controller_active') === 'true');
		if (!$frontControllerActive) {
			$url .= '/index.php';
		}

		return $url;
	}


	/**
	 * Display the navigation page of the Social app.
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 *
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	public function timeline(string $path = ''): TemplateResponse {
		return $this->navigate();
	}

	/**
	 * Display the navigation page of the Social app.
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 *
	 * @param string $path
	 *
	 * @return TemplateResponse
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	public function account(string $path = ''): TemplateResponse {
		return $this->navigate();
	}


	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $id
	 *
	 * @return Response
	 */
	public function documentGet(string $id): Response {
		try {
			$mime = '';
			$file = $this->documentService->getFromCache($id, $mime);

			return new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $mime]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $id
	 *
	 * @return Response
	 */
	public function documentGetPublic(string $id): Response {
		try {
			$mime = '';
			$file = $this->documentService->getFromCache($id, $mime, true);

			return new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $mime]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $id
	 *
	 * @return Response
	 */
	public function resizedGet(string $id): Response {
		try {
			$mime = '';
			$file = $this->documentService->getResizedFromCache($id, $mime);

			return new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $mime]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $id
	 *
	 * @return Response
	 */
	public function resizedGetPublic(string $id): Response {
		try {
			$mime = '';
			$file = $this->documentService->getResizedFromCache($id, $mime, true);

			return new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $mime]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}
}
