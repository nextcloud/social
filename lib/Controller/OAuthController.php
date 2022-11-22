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

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\InstanceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class OAuthController extends Controller {
	private IUserSession $userSession;
	private IURLGenerator $urlGenerator;
	private InstanceService $instanceService;
	private AccountService $accountService;
	private CacheActorService $cacheActorService;
	private ClientService $clientService;
	private ConfigService $configService;
	private LoggerInterface $logger;
	private IInitialState $initialState;

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		IURLGenerator $urlGenerator,
		InstanceService $instanceService,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		ClientService $clientService,
		ConfigService $configService,
		LoggerInterface $logger,
		IInitialState $initialState
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->userSession = $userSession;
		$this->urlGenerator = $urlGenerator;
		$this->instanceService = $instanceService;
		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->clientService = $clientService;
		$this->configService = $configService;
		$this->logger = $logger;
		$this->initialState = $initialState;

		$body = file_get_contents('php://input');
		$logger->debug('[OAuthController] input: ' . $body);
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function nodeinfo2(): Response {
		try {
			$local = $this->instanceService->getLocal();
			$name = $local->getTitle();

			$version = $local->getVersion();
			$usage = $local->getUsage();
			$openReg = $local->isRegistrations();
		} catch (InstanceDoesNotExistException $e) {
			$name = 'Nextcloud Social';
			$version = $this->configService->getAppValue('installed_version');
			$usage = [];
			$openReg = false;
		}

		$nodeInfo = [
			"version" => "2.0",
			"software" => [
				"name" => $name,
				"version" => $version
			],
			"protocols" => [
				"activitypub"
			],
			"rootUrl" => rtrim($this->urlGenerator->linkToRouteAbsolute('social.Navigation.navigate'), '/'),
			"usage" => $usage,
			"openRegistrations" => $openReg
		];

		return new DataResponse($nodeInfo, Http::STATUS_OK);
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param array|string $redirect_uris
	 *
	 * @throws ClientException
	 */
	public function apps(
		string $client_name = '',
		$redirect_uris = '',
		string $website = '',
		string $scopes = 'read'
	): DataResponse {
		// TODO: manage array from request
		if (!is_array($redirect_uris)) {
			$redirect_uris = [$redirect_uris];
		}

		$client = new SocialClient();
		$client->setAppWebsite($website);
		$client->setAppRedirectUris($redirect_uris);
		$client->setAppScopes($client->getScopesFromString($scopes));
		$client->setAppName($client_name);

		$this->clientService->createApp($client);

		return new DataResponse(
			[
				'id' => $client->getId(),
				'name' => $client->getAppName(),
				'website' => $client->getAppWebsite(),
				'scopes' => implode(' ', $client->getAppScopes()),
				'client_id' => $client->getAppClientId(),
				'client_secret' => $client->getAppClientSecret()
			], Http::STATUS_OK
		);
	}


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
	public function authorize(
		string $client_id,
		string $redirect_uri,
		string $response_type,
		string $scope = 'read'
	): Response {
		$user = $this->userSession->getUser();

		// check actor exists
		$this->accountService->getActorFromUserId($user->getUID());

		if ($response_type !== 'code') {
			throw new ClientNotFoundException('invalid response type');
		}

		// check client exists in db
		$client = $this->clientService->getFromClientId($client_id);
		$this->initialState->provideInitialState('appName', $client->getAppName());

		return new TemplateResponse(Application::APP_NAME, 'oauth2', [
			'request' =>
				[
					'clientId' => $client_id,
					'redirectUri' => $redirect_uri,
					'responseType' => $response_type,
					'scope' => $scope
				]
		]);
	}


	/**
	 * @NoAdminRequired
	 */
	public function authorizing(
		string $client_id,
		string $redirect_uri,
		string $response_type,
		string $scope = 'read'
	): DataResponse {
		try {
			$user = $this->userSession->getUser();
			$account = $this->accountService->getActorFromUserId($user->getUID());

			if ($response_type !== 'code') {
				throw new ClientNotFoundException('invalid response type');
			}

			$client = $this->clientService->getFromClientId($client_id);
			$this->clientService->confirmData(
				$client,
				[
					'app_scopes' => $scope,
					'redirect_uri', $redirect_uri
				]
			);

			$client->setAuthScopes($client->getScopesFromString($scope));
			$client->setAuthAccount($account->getPreferredUsername());
			$client->setAuthUserId($user->getUID());

			$this->clientService->authClient($client);
			$code = $client->getAuthCode();

			if ($redirect_uri !== 'urn:ietf:wg:oauth:2.0:oob') {
				header('Location: ' . $redirect_uri . '?code=' . $code);
				exit();
			}

			// TODO : finalize result if no redirect_url
			return new DataResponse(
				['code' => $code], Http::STATUS_OK
			);
		} catch (Exception $e) {
			$this->logger->notice($e->getMessage() . ' ' . get_class($e));

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 */
	public function token(
		string $client_id,
		string $client_secret,
		string $redirect_uri,
		string $grant_type,
		string $scope = 'read',
		string $code = ''
	): DataResponse {
		try {
			$client = $this->clientService->getFromClientId($client_id);
			$this->clientService->confirmData(
				$client,
				[
					'client_secret' => $client_secret,
					'redirect_uri' => $redirect_uri,
					'auth_scopes' => $scope
				]
			);

			if ($grant_type === 'authorization_code') {
				if ($code === '') {
					return new DataResponse(['error' => 'missing code'], Http::STATUS_BAD_REQUEST);
				}

				$this->clientService->confirmData($client, ['code' => $code]);
				$this->clientService->generateToken($client);
			} elseif ($grant_type === 'client_credentials') {
				// TODO: manage client_credentials
			} else {
				return new DataResponse(
					['error' => 'invalid value for grant_type'], Http::STATUS_BAD_REQUEST
				);
			}

			if ($client->getToken() === '') {
				return new DataResponse(
					['error' => 'issue generating access_token'], Http::STATUS_BAD_REQUEST
				);
			}

			return new DataResponse(
				[
					"access_token" => $client->getToken(),
					"token_type" => 'Bearer',
					"scope" => $scope,
					"created_at" => $client->getCreation()
				], Http::STATUS_OK
			);
		} catch (ClientNotFoundException $e) {
			return new DataResponse(['error' => 'unknown client_id'], Http::STATUS_UNAUTHORIZED);
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNAUTHORIZED);
		}
	}
}
