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


use daita\MySmallPhpTools\Traits\Nextcloud\TNCDataResponse;
use daita\MySmallPhpTools\Traits\TArrayTools;
use Exception;
use OC;
use OCP\AppFramework\Http;
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IInitialStateService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;


/**
 * Class NavigationController
 *
 * @package OCA\Social\Controller
 */
class NavigationController extends Controller {


	use TArrayTools;
	use TNCDataResponse;


	/** @var string */
	private $userId;

	/** @var IConfig */
	private $config;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var AccountService */
	private $accountService;

	private $documentService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;

	/** @var IL10N */
	private $l10n;

	/** @var CheckService */
	private $checkService;


	/**
	 * NavigationController constructor.
	 *
	 * @param IL10N $l10n
	 * @param IRequest $request
	 * @param string $userId
	 * @param IConfig $config
	 * @param IURLGenerator $urlGenerator
	 * @param AccountService $accountService
	 * @param DocumentService $documentService
	 * @param ConfigService $configService
	 * @param CheckService $checkService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IL10N $l10n, IRequest $request, $userId, IConfig $config, IInitialStateService $initialStateService, IURLGenerator $urlGenerator,
		AccountService $accountService, DocumentService $documentService,
		ConfigService $configService, CheckService $checkService, MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);

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
	 * @param string $path
	 *
	 * @return TemplateResponse
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	public function navigate(string $path = ''): TemplateResponse {
		$serverData = [
			'public'   => false,
			'firstrun' => false,
			'setup'    => false,
			'isAdmin'  => OC::$server->getGroupManager()
									 ->isAdmin($this->userId),
			'cliUrl'   => $this->getCliUrl()
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
						$this->initialStateService->provideInitialState(Application::APP_NAME, 'serverData', $serverData);
						return new TemplateResponse(Application::APP_NAME, 'main');
					}
				}
			}
		}

		try {
			$this->configService->getSocialUrl();
		} catch (SocialAppConfigException $e) {
			$this->configService->setSocialUrl();
		}

		if ($serverData['isAdmin']) {
			$checks = $this->checkService->checkDefault();
			$serverData['checks'] = $checks;
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

		$this->initialStateService->provideInitialState(Application::APP_NAME, 'serverData', $serverData);
		return new TemplateResponse(Application::APP_NAME, 'main');
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
	 * @param string $path
	 *
	 * @return TemplateResponse
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

