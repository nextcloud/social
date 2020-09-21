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

namespace OCA\Social\Service;


use daita\MySmallPhpTools\Traits\TStringTools;
use Exception;
use OCA\Social\Db\ClientRequest;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\Client\SocialClient;


/**
 * Class ClientService
 *
 * @package OCA\Social\Service
 */
class ClientService {


	const TIME_TOKEN_REFRESH = 300; // 5m
//	const TIME_TOKEN_TTL = 21600; // 6h
//	const TIME_AUTH_TTL = 30672000; // 1y

// looks like there is no token refresh. token must have been updated in the last year.
	const TIME_TOKEN_TTL = 30672000; // 1y


	use TStringTools;


	/** @var ClientRequest */
	private $clientRequest;

	/** @var MiscService */
	private $miscService;


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

