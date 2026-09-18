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
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The app registrations in `social_client`.
 *
 * Only the registration: who authorized an app, with which scopes, and the
 * code and token that came of it belong to `social_client_auth` and are
 * written and read through `ClientAuthRequest`.
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
}
