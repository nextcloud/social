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


use daita\MySmallPhpTools\Traits\Nextcloud\TNCDataResponse;
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
use OCA\Social\Service\MiscService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;


class OAuthController extends Controller {


	use TNCDataResponse;


	/** @var IUserSession */
	private $userSession;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var InstanceService */
	private $instanceService;

	/** @var AccountService */
	private $accountService;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var ClientService */
	private $clientService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * ActivityStreamController constructor.
	 *
	 * @param IRequest $request
	 * @param IUserSession $userSession
	 * @param IURLGenerator $urlGenerator
	 * @param InstanceService $instanceService
	 * @param AccountService $accountService
	 * @param CacheActorService $cacheActorService
	 * @param ClientService $clientService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IRequest $request, IUserSession $userSession, IURLGenerator $urlGenerator,
		InstanceService $instanceService, AccountService $accountService,
		CacheActorService $cacheActorService, ClientService $clientService, ConfigService $configService,
		MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->userSession = $userSession;
		$this->urlGenerator = $urlGenerator;
		$this->instanceService = $instanceService;
		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->clientService = $clientService;
		$this->configService = $configService;
		$this->miscService = $miscService;

		$body = file_get_contents('php://input');
		$this->miscService->log('[OAuthController] input: ' . $body, 0);
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return Response
	 */
	public function nodeinfo(): Response {
		$nodeInfo = [
			'links' => [
				'rel'  => 'http://nodeinfo.diaspora.software/ns/schema/2.0',
				'href' => $this->urlGenerator->linkToRouteAbsolute('social.OAuth.nodeinfo2')
			]
		];

		return new DataResponse($nodeInfo, Http::STATUS_OK);
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return Response
	 */
	public function nodeinfo2() {
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
			"version"           => "2.0",
			"software"          => [
				"name"    => $name,
				"version" => $version
			],
			"protocols"         => [
				"activitypub"
			],
			"usage"             => $usage,
			"openRegistrations" => $openReg
		];

		return new DataResponse($nodeInfo, Http::STATUS_OK);
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $website
	 * @param string $redirect_uris
	 * @param string $scopes
	 * @param string $client_name
	 *
	 * @return Response
	 * @throws ClientException
	 */
	public function apps(
		string $client_name = '', string $redirect_uris = '', string $website = '', string $scopes = 'read'
	): Response {
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
				'id'            => $client->getId(),
				'name'          => $client->getAppName(),
				'website'       => $client->getAppWebsite(),
				'scopes'        => implode(' ', $client->getAppScopes()),
				'client_id'     => $client->getAppClientId(),
				'client_secret' => $client->getAppClientSecret()
			], Http::STATUS_OK
		);
	}


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 *
	 * @param string $client_id
	 * @param string $redirect_uri
	 * @param string $response_type
	 * @param string $scope
	 *
	 * @return DataResponse
	 */
	public function authorize(
		string $client_id, string $redirect_uri, string $response_type, string $scope = 'read'
	): DataResponse {
		try {
			$user = $this->userSession->getUser();
			$account = $this->accountService->getActorFromUserId($user->getUID());

			if ($response_type !== 'code') {
				return new DataResponse(['error' => 'invalid_type'], Http::STATUS_BAD_REQUEST);
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
				[
//				'access_token' => '',
//				"token_type"   => "Bearer",
//				"scope"        => "read write follow push",
//				"created_at"   => 1573979017
				], Http::STATUS_OK
			);
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNAUTHORIZED);
		}
	}


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 *
	 * @param string $client_id
	 * @param string $client_secret
	 * @param string $redirect_uri
	 * @param string $grant_type
	 * @param string $scope
	 * @param string $code
	 *
	 * @return DataResponse
	 */
	public function token(
		string $client_id, string $client_secret, string $redirect_uri, string $grant_type,
		string $scope = 'read', string $code = ''
	) {
		try {
			$client = $this->clientService->getFromClientId($client_id);
			$this->clientService->confirmData(
				$client,
				[
					'client_secret' => $client_secret,
					'redirect_uri'  => $redirect_uri,
					'auth_scopes'   => $scope
				]
			);

			if ($grant_type === 'authorization_code') {
				if ($code === '') {
					return new DataResponse(['error' => 'missing code'], Http::STATUS_BAD_REQUEST);
				}

				$this->clientService->confirmData($client, ['code' => $code]);
				$this->clientService->generateToken($client);
			} else if ($grant_type === 'client_credentials') {
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
					"token_type"   => 'Bearer',
					"scope"        => $scope,
					"created_at"   => $client->getCreation()
				], Http::STATUS_OK
			);
		} catch (ClientNotFoundException $e) {
			return new DataResponse(['error' => 'unknown client_id'], Http::STATUS_UNAUTHORIZED);
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNAUTHORIZED);
		}
	}

}

