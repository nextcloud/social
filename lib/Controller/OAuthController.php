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

use OCA\Social\Entity\Account;
use OCA\Social\Entity\Instance;
use OCA\Social\Repository\InstanceRepository;
use OCA\Social\Tools\Traits\TNCDataResponse;
use Exception;
use OCA\Social\Entity\Application;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ApplicationService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\MiscService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\ORM\IEntityManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class OAuthController extends Controller {
	use TNCDataResponse;

	private IUserSession $userSession;
	private IURLGenerator $urlGenerator;
	private InstanceService $instanceService;
	private AccountService $accountService;
	private CacheActorService $cacheActorService;
	private ApplicationService $clientService;
	private ConfigService $configService;
	private MiscService $miscService;
	private IEntityManager $entityManager;

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		IURLGenerator $urlGenerator,
		InstanceService $instanceService,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		ApplicationService $clientService,
		ConfigService $configService,
		MiscService $miscService,
		IEntityManager $entityManager
	) {
		parent::__construct('social', $request);

		$this->userSession = $userSession;
		$this->urlGenerator = $urlGenerator;
		$this->instanceService = $instanceService;
		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->clientService = $clientService;
		$this->configService = $configService;
		$this->miscService = $miscService;
		$this->entityManager = $entityManager;
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function index(): DataResponse {
		$nodeInfo = [
			'links' => [
				'rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.0',
				'href' => $this->urlGenerator->linkToRouteAbsolute('social.OAuth.show')
			]
		];

		return new DataResponse($nodeInfo, Http::STATUS_OK);
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function show(): DataResponse {
		$query = $this->entityManager->createQuery('SELECT COUNT(a) FROM \OCA\Social\Entity\Account a');
		$query->setCacheable(true);
		$countUser = $query->getSingleScalarResult();

		$nodeInfo = [
			"version" => "2.0",
			"software" => [
				"name" => 'Nextcloud Social',
				"version" => $this->configService->getAppValue('installed_version'),
			],
			"protocols" => [
				"activitypub"
			],
			"usage" => [
				"total" => (int)$countUser,
			],
			"openRegistrations" => false,
		];

		return new DataResponse($nodeInfo, Http::STATUS_OK);
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 * @param array|string $redirect_uris
	 * @throws ClientException
	 */
	public function apps(
		string $client_name = '', $redirect_uris = '', string $website = '', string $scopes = 'read'
	): DataResponse {
		// TODO: manage array from request
		if (!is_array($redirect_uris)) {
			$redirect_uris = [$redirect_uris];
		}

		$client = new Application();
		$client->setAppWebsite($website);
		$client->setAppRedirectUris($redirect_uris);
		$client->setAppScopes(Application::getScopesFromString($scopes));
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
		string $client_id, string $redirect_uri, string $response_type, string $scope = 'read'
	): DataResponse {
		try {
			$user = $this->userSession->getUser();
			$accountRepository = $this->entityManager->getRepository(Account::class);
			$accountRepository->findBy([
				''
			]);

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
					'code' => $code,
					//				'access_token' => '',
					//				"token_type"   => "Bearer",
					//				"scope"        => "read write follow push",
					//				"created_at"   => 1573979017
				], Http::STATUS_OK
			);
		} catch (Exception $e) {
			$this->miscService->log($e->getMessage() . ' ' . get_class($e));
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNAUTHORIZED);
		}
	}


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 */
	public function token(
		string $client_id, string $client_secret, string $redirect_uri, string $grant_type,
		string $scope = 'read', string $code = ''
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
