<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use Exception;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\ClientService;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Class ClientAppRequest
 *
 * @package OCA\Social\Db
 */
class ClientRequest extends ClientRequestBuilder {
	use TArrayTools;

	/**
	 * Insert a new OAuth client in the database.
	 * @throws \OCP\DB\Exception
	 */
	public function saveApp(SocialClient $client): void {
		$qb = $this->getClientInsertSql();
		$qb->setValue('app_name', $qb->createNamedParameter($client->getAppName()))
			->setValue('app_website', $qb->createNamedParameter($client->getAppWebsite()))
			->setValue(
				'app_redirect_uris', $qb->createNamedParameter(json_encode($client->getAppRedirectUris()))
			)
			->setValue('app_client_id', $qb->createNamedParameter($client->getAppClientId()))
			->setValue('app_client_secret', $qb->createNamedParameter($this->secretHasher->hash($client->getAppClientSecret())))
			->setValue('app_scopes', $qb->createNamedParameter(json_encode($client->getAppScopes())));

		try {
			$dt = new DateTime('now');
			$qb->setValue('last_update', $qb->createNamedParameter($dt, IQueryBuilder::PARAM_DATE));
			$qb->setValue('creation', $qb->createNamedParameter($dt, IQueryBuilder::PARAM_DATE));
		} catch (Exception $e) {
		}

		$qb->executeStatement();

		$client->setId($qb->getLastInsertId());
	}

	/**
	 * @param SocialClient $client
	 */
	public function authClient(SocialClient $client): void {
		$qb = $this->getClientUpdateSql();
		$qb->set('auth_code', $qb->createNamedParameter($this->secretHasher->hash($client->getAuthCode())));
		$qb->set('auth_scopes', $qb->createNamedParameter(json_encode($client->getAuthScopes())));
		$qb->set('auth_account', $qb->createNamedParameter($client->getAuthAccount()));
		$qb->set('auth_user_id', $qb->createNamedParameter($client->getAuthUserId()));
		// The row holds one token and one auth_user_id. Leaving the token in place
		// while the user changes would let a token issued to the previous user act as
		// the new one, so a fresh authorization invalidates it.
		$qb->set('token', $qb->createNamedParameter(''));

		// the authorization moment: the code is only exchangeable for
		// ClientService::TIME_CODE_TTL from here
		try {
			$qb->set('last_update', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		} catch (Exception $e) {
		}

		$qb->limitToId($client->getId());

		$qb->executeStatement();
	}

	/**
	 * @param SocialClient $client
	 */
	public function updateToken(SocialClient $client): void {
		$qb = $this->getClientUpdateSql();
		$qb->set('token', $qb->createNamedParameter($this->secretHasher->hash($client->getToken())));
		$qb->set('auth_code', $qb->createNamedParameter(''));

		$qb->limitToId($client->getId());

		$qb->executeStatement();
	}

	/**
	 * Clears the access token (and any pending code) of a client row.
	 */
	public function revokeToken(SocialClient $client): void {
		$qb = $this->getClientUpdateSql();
		$qb->set('token', $qb->createNamedParameter(''));
		$qb->set('auth_code', $qb->createNamedParameter(''));

		$qb->limitToId($client->getId());

		$qb->executeStatement();
	}

	/**
	 * @param SocialClient $client
	 */
	public function updateTime(SocialClient $client): void {
		$now = new DateTime('now');
		$client->setLastUpdate($now->getTimestamp());

		$qb = $this->getClientUpdateSql();
		$qb->set('last_update', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE));

		$qb->limitToId($client->getId());

		$qb->executeStatement();
	}

	/**
	 * @param string $clientId
	 *
	 * @return SocialClient
	 * @throws ClientNotFoundException
	 */
	public function getFromClientId(string $clientId): SocialClient {
		$qb = $this->getClientSelectSql();
		$qb->limitToAppClientId($clientId);

		return $this->getClientFromRequest($qb);
	}

	/**
	 * @param string $token
	 *
	 * @return SocialClient
	 * @throws ClientNotFoundException
	 */
	public function getFromToken(string $token): SocialClient {
		// tokens are stored hashed; rows from before hashing hold the bare value
		foreach ($this->secretHasher->forLookup($token) as $stored) {
			try {
				$qb = $this->getClientSelectSql();
				$qb->limitToToken($stored);

				return $this->getClientFromRequest($qb);
			} catch (ClientNotFoundException $e) {
			}
		}

		throw new ClientNotFoundException();
	}

	/**
	 * Removes an app registration, and with it every authorization against it.
	 *
	 * Nothing in the app calls this on its own: a registration is a thing a
	 * client made and may come back to. It exists so that an operator, and the
	 * tests, can take one away without leaving authorizations pointing at a
	 * row that is gone.
	 */
	public function deleteApp(string $appClientId): void {
		try {
			$client = $this->getFromClientId($appClientId);
		} catch (ClientNotFoundException $e) {
			return;
		}

		$auth = $this->getQueryBuilder();
		$auth->delete(self::TABLE_CLIENT_AUTH)
			->where($auth->expr()->eq('client_id', $auth->createNamedParameter($client->getId(), IQueryBuilder::PARAM_INT)));
		$auth->executeStatement();

		$qb = $this->getClientDeleteSql();
		$qb->limitToId($client->getId());
		$qb->executeStatement();
	}

	/**
	 * @throws Exception
	 */
	public function deprecateToken() {
		$qb = $this->getClientDeleteSql();

		$date = new DateTime();
		$date->setTimestamp(time() - ClientService::TIME_TOKEN_TTL);
		$qb->limitToDBFieldDateTime('last_update', $date, true);

		$qb->executeStatement();
	}
}
