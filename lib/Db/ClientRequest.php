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
		   ->setValue('app_client_secret', $qb->createNamedParameter($client->getAppClientSecret()))
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
		$qb->set('auth_code', $qb->createNamedParameter($client->getAuthCode()));
		$qb->set('auth_scopes', $qb->createNamedParameter(json_encode($client->getAuthScopes())));
		$qb->set('auth_account', $qb->createNamedParameter($client->getAuthAccount()));
		$qb->set('auth_user_id', $qb->createNamedParameter($client->getAuthUserId()));

		$qb->limitToId($client->getId());

		$qb->executeStatement();
	}


	/**
	 * @param SocialClient $client
	 */
	public function updateToken(SocialClient $client): void {
		$qb = $this->getClientUpdateSql();
		$qb->set('token', $qb->createNamedParameter($client->getToken()));
		$qb->set('auth_code', $qb->createNamedParameter(''));

		$qb->limitToId($client->getId());

		$qb->execute();
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

		$qb->execute();
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
		$qb = $this->getClientSelectSql();
		$qb->limitToToken($token);

		return $this->getClientFromRequest($qb);
	}


	/**
	 * @throws Exception
	 */
	public function deprecateToken() {
		$qb = $this->getClientDeleteSql();

		$date = new DateTime();
		$date->setTimestamp(time() - ClientService::TIME_TOKEN_TTL);
		$qb->limitToDBFieldDateTime('last_update', $date, true);

		$qb->execute();
	}
}
