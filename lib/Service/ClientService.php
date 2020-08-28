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
use OCA\Social\Db\ClientAppRequest;
use OCA\Social\Db\ClientAuthRequest;
use OCA\Social\Db\ClientTokenRequest;
use OCA\Social\Exceptions\ClientAppDoesNotExistException;
use OCA\Social\Exceptions\ClientAuthDoesNotExistException;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Model\Client\ClientApp;
use OCA\Social\Model\Client\ClientAuth;
use OCA\Social\Model\Client\ClientToken;


/**
 * Class ClientService
 *
 * @package OCA\Social\Service
 */
class ClientService {


	use TStringTools;


	/** @var ClientAppRequest */
	private $clientAppRequest;

	/** @var ClientAuthRequest */
	private $clientAuthRequest;

	/** @var ClientTokenRequest */
	private $clientTokenRequest;

	/** @var MiscService */
	private $miscService;


	/**
	 * ClientService constructor.
	 *
	 * @param ClientAppRequest $clientAppRequest
	 * @param ClientAuthRequest $clientAuthRequest
	 * @param ClientTokenRequest $clientTokenRequest
	 * @param MiscService $miscService
	 */
	public function __construct(
		ClientAppRequest $clientAppRequest, ClientAuthRequest $clientAuthRequest,
		ClientTokenRequest $clientTokenRequest, MiscService $miscService
	) {
		$this->clientAppRequest = $clientAppRequest;
		$this->clientAuthRequest = $clientAuthRequest;
		$this->clientTokenRequest = $clientTokenRequest;
		$this->miscService = $miscService;
	}


	/**
	 * @param ClientApp $clientApp
	 *
	 * @throws ClientException
	 */
	public function createClient(ClientApp $clientApp): void {
		if ($clientApp->getName() === '') {
			throw new ClientException('missing client_name');
		}

		if (empty($clientApp->getRedirectUris())) {
			throw new ClientException('missing redirect_uris');
		}

		$clientApp->setClientId($this->token(40));
		$clientApp->setClientSecret($this->token(40));

		$this->clientAppRequest->save($clientApp);
	}


	/**
	 * @param ClientAuth $clientAuth
	 * @param ClientApp $clientApp
	 *
	 * @throws ClientException
	 */
	public function authClient(ClientApp $clientApp, ClientAuth $clientAuth) {
		$this->confirmData($clientApp, ['redirect_uri' => $clientAuth->getRedirectUri()]);

		$clientAuth->setCode($this->token(60));
		$clientAuth->setClientId($clientApp->getId());

		$this->clientAuthRequest->save($clientAuth);
	}


	/**
	 * @param ClientApp $clientApp
	 * @param ClientAuth $clientAuth
	 * @param ClientToken $clientToken
	 */
	public function generateToken(ClientApp $clientApp, ClientAuth $clientAuth, ClientToken $clientToken
	): void {
		$clientToken->setAuthId($clientAuth->getId());
		$clientToken->setToken($this->token(80));

		$this->clientTokenRequest->save($clientToken);
	}


	/**
	 * @param string $clientId
	 *
	 * @return ClientApp
	 * @throws ClientAppDoesNotExistException
	 */
	public function getClientByClientId(string $clientId): ClientApp {
		return $this->clientAppRequest->getByClientId($clientId);
	}

	/**
	 * @param string $code
	 *
	 * @return ClientAuth
	 * @throws ClientAuthDoesNotExistException
	 */
	public function getAuthByCode(string $code): ClientAuth {
		return $this->clientAuthRequest->getByCode($code);
	}


	/**
	 * @param string $token
	 *
	 * @return ClientAuth
	 * @throws ClientAuthDoesNotExistException
	 */
	public function getAuthFromToken(string $token): ClientAuth {
		return $this->clientAuthRequest->getByToken($token);
	}


	/**
	 * @param ClientApp $clientApp
	 * @param array $data
	 *
	 * @throws ClientException
	 */
	public function confirmData(ClientApp $clientApp, array $data) {
		if (array_key_exists('redirect_uri', $data)
			&& !in_array($data['redirect_uri'], $clientApp->getRedirectUris())) {
			throw new ClientException('unknown redirect_uri');
		}

		if (array_key_exists('client_secret', $data)
			&& $data['client_secret'] !== $clientApp->getClientSecret()) {
			throw new ClientException('wrong client_secret');
		}

		if (array_key_exists('scopes', $data)) {
			$scopes = $data['scopes'];
			if (!is_array($scopes)) {
				$scopes = explode(' ', $scopes);
			}

			foreach ($scopes as $scope) {
				if (!in_array($scope, $clientApp->getScopes())) {
					throw new ClientException('invalid scope');
				}
			}
		}

	}


}

