<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Db\ClientRequest;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Tools\Traits\TStringTools;

/**
 * Class ClientService
 *
 * @package OCA\Social\Service
 */
class ClientService {
	public const TIME_TOKEN_REFRESH = 300; // 5m
	//	const TIME_TOKEN_TTL = 21600; // 6h
	//	const TIME_AUTH_TTL = 30672000; // 1y

	// looks like there is no token refresh. token must have been updated in the last year.
	public const TIME_TOKEN_TTL = 30672000; // 1y


	use TStringTools;


	private ClientRequest $clientRequest;

	private MiscService $miscService;


	/**
	 * ClientService constructor.
	 *
	 * @param ClientRequest $clientRequest
	 * @param MiscService $miscService
	 */
	public function __construct(ClientRequest $clientRequest, MiscService $miscService) {
		$this->clientRequest = $clientRequest;
		$this->miscService = $miscService;
	}


	/**
	 * @param SocialClient $client
	 *
	 * @throws ClientException
	 */
	public function createApp(SocialClient $client): void {
		if ($client->getAppName() === '') {
			throw new ClientException('missing client_name');
		}

		if (empty($client->getAppRedirectUris())) {
			throw new ClientException('missing redirect_uris');
		}

		$client->setAppClientId($this->token(40));
		$client->setAppClientSecret($this->token(40));

		$this->clientRequest->saveApp($client);
	}


	/**
	 * @param SocialClient $client
	 */
	public function authClient(SocialClient $client) {
		$client->setAuthCode($this->token(60));
		//		$clientAuth->setClientId($client->getId());

		$this->clientRequest->authClient($client);
	}


	/**
	 * @param SocialClient $client
	 */
	public function generateToken(SocialClient $client): void {
		$client->setToken($this->token(80));

		$this->clientRequest->updateToken($client);
	}


	/**
	 * @param string $clientId
	 *
	 * @return SocialClient
	 * @throws ClientNotFoundException
	 */
	public function getFromClientId(string $clientId): SocialClient {
		return $this->clientRequest->getFromClientId($clientId);
	}


	/**
	 * @param string $token
	 *
	 * @return SocialClient
	 * @throws ClientNotFoundException
	 */
	public function getFromToken(string $token): SocialClient {
		$client = $this->clientRequest->getFromToken($token);

		if ($client->getLastUpdate() + self::TIME_TOKEN_TTL < time()) {
			try {
				$this->clientRequest->deprecateToken();
			} catch (Exception $e) {
			}

			throw new ClientNotFoundException();
		}

		if ($client->getLastUpdate() + self::TIME_TOKEN_REFRESH > time()) {
			$this->clientRequest->updateTime($client);
		}

		return $client;
	}


	/**
	 * @param SocialClient $client
	 * @param array $data
	 *
	 * @throws ClientException
	 */
	public function confirmData(SocialClient $client, array $data) {
		if (array_key_exists('redirect_uri', $data)
			&& !in_array($data['redirect_uri'], $client->getAppRedirectUris())) {
			throw new ClientException('unknown redirect_uri');
		}

		if (array_key_exists('client_secret', $data)
			&& $data['client_secret'] !== $client->getAppClientSecret()) {
			throw new ClientException('wrong client_secret');
		}

		if (array_key_exists('app_scopes', $data)) {
			$scopes = $data['app_scopes'];
			if (!is_array($scopes)) {
				$scopes = $client->getScopesFromString($scopes);
			}

			foreach ($scopes as $scope) {
				if (!in_array($scope, $client->getAppScopes())) {
					throw new ClientException('invalid scope');
				}
			}
		}

		if (array_key_exists('auth_scopes', $data)) {
			$scopes = $data['auth_scopes'];
			if (!is_array($scopes)) {
				$scopes = $client->getScopesFromString($scopes);
			}

			foreach ($scopes as $scope) {
				if (!in_array($scope, $client->getAuthScopes())) {
					throw new ClientException('invalid scope');
				}
			}
		}

		if (array_key_exists('code', $data) && $data['code'] !== $client->getAuthCode()) {
			throw new ClientException('unknown code');
		}
	}
}
