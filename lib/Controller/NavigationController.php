<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
use Psr\Log\LoggerInterface;

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
	private LoggerInterface $logger;

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
		MiscService $miscService,
		LoggerInterface $logger,
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
		$this->logger = $logger;
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
		$this->logger->info('[NavigationController] navigate() called', [
			'path' => $path,
			'userId' => $this->userId,
		]);

		$serverData = [
			'public' => false,
			'firstrun' => false,
			'setup' => false,
			'isAdmin' => $this->userId !== null && Server::get(IGroupManager::class)
				->isAdmin($this->userId),
			'cliUrl' => $this->getCliUrl()
		];

		$this->logger->debug('[NavigationController] Initial serverData', ['serverData' => $serverData]);

		try {
			$serverData['cloudAddress'] = $this->configService->getCloudUrl();
			$this->logger->info('[NavigationController] Cloud address configured', [
				'cloudAddress' => $serverData['cloudAddress']
			]);
		} catch (SocialAppConfigException $e) {
			$this->logger->warning('[NavigationController] Cloud address not configured, attempting setup', [
				'exception' => $e->getMessage()
			]);
			$this->checkService->checkInstallationStatus(true);
			$cloudAddress = $this->setupCloudAddress();
			if ($cloudAddress !== '') {
				$serverData['cloudAddress'] = $cloudAddress;
				$this->logger->info('[NavigationController] Cloud address auto-configured', [
					'cloudAddress' => $cloudAddress
				]);
			} else {
				$serverData['setup'] = true;
				$this->logger->warning('[NavigationController] Setup required - cloud address not configured');

				if ($serverData['isAdmin']) {
					$cloudAddress = $this->request->getParam('cloudAddress');
					if ($cloudAddress !== null) {
						$this->configService->setCloudUrl($cloudAddress);
						$this->logger->info('[NavigationController] Cloud address set from request', [
							'cloudAddress' => $cloudAddress
						]);
					} else {
						$this->logger->info('[NavigationController] Returning setup page (admin user)');
						$this->initialStateService->provideInitialState(Application::APP_ID, 'serverData', $serverData);
						return new TemplateResponse(Application::APP_ID, 'main');
					}
				} else {
					$this->logger->info('[NavigationController] Returning setup page (non-admin user)');
				}
			}
		}

		try {
			$socialUrl = $this->configService->getSocialUrl();
			$this->logger->debug('[NavigationController] Social URL retrieved', ['socialUrl' => $socialUrl]);
		} catch (SocialAppConfigException $e) {
			$this->logger->info('[NavigationController] Setting social URL', ['exception' => $e->getMessage()]);
			$this->configService->setSocialUrl();
		}

		/*
		 * Create social user account if it doesn't exist yet
		 */
		try {
			$this->accountService->createActor($this->userId, $this->userId);
			$serverData['firstrun'] = true;
			$this->logger->info('[NavigationController] Created new actor for user', [
				'userId' => $this->userId
			]);
		} catch (AccountAlreadyExistsException $e) {
			$this->logger->debug('[NavigationController] Actor already exists for user', [
				'userId' => $this->userId
			]);
		} catch (NoUserException $e) {
			$this->logger->error('[NavigationController] User does not exist', [
				'userId' => $this->userId,
				'exception' => $e->getMessage()
			]);
		} catch (SocialAppConfigException $e) {
			$this->logger->error('[NavigationController] Config error while creating actor', [
				'userId' => $this->userId,
				'exception' => $e->getMessage()
			]);
		}

		if ($serverData['isAdmin']) {
			$checks = $this->checkService->checkDefault();
			$serverData['checks'] = $checks;
			$this->logger->debug('[NavigationController] Admin checks completed', ['checks' => $checks]);
		}

		$this->logger->info('[NavigationController] Providing initial state and rendering template', [
			'serverData' => $serverData
		]);
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
		$this->logger->debug('[NavigationController] documentGet called', ['id' => $id]);
		try {
			$mime = '';
			$file = $this->documentService->getFromCache($id, $mime);
			$this->logger->info('[NavigationController] Document retrieved from cache', [
				'id' => $id,
				'mime' => $mime
			]);

			return new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $mime]);
		} catch (Exception $e) {
			$this->logger->error('[NavigationController] Failed to get document', [
				'id' => $id,
				'exception' => $e->getMessage()
			]);
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
		$this->logger->debug('[NavigationController] documentGetPublic called', ['id' => $id]);
		try {
			$mime = '';
			$file = $this->documentService->getFromCache($id, $mime, true);
			$this->logger->info('[NavigationController] Public document retrieved from cache', [
				'id' => $id,
				'mime' => $mime
			]);

			return new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $mime]);
		} catch (Exception $e) {
			$this->logger->error('[NavigationController] Failed to get public document', [
				'id' => $id,
				'exception' => $e->getMessage()
			]);
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
