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


namespace OCA\Social\Db;


use daita\MySmallPhpTools\Traits\TArrayTools;
use DateTime;
use Exception;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\ClientService;
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
	 *
	 * @param SocialClient $client
	 */
	public function saveApp(SocialClient $client) {
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

		$qb->execute();

		$client->setId($qb->getLastInsertId());
	}


	/**
	 * @param SocialClient $client
	 */
	public function authClient(SocialClient $client) {
		$qb = $this->getClientUpdateSql();
		$qb->set('auth_code', $qb->createNamedParameter($client->getAuthCode()));
		$qb->set('auth_scopes', $qb->createNamedParameter(json_encode($client->getAuthScopes())));
		$qb->set('auth_account', $qb->createNamedParameter($client->getAuthAccount()));
		$qb->set('auth_user_id', $qb->createNamedParameter($client->getAuthUserId()));

		$qb->limitToId($client->getId());

		$qb->execute();
	}


	/**
	 * @param SocialClient $client
	 */
	public function updateToken(SocialClient $client) {
		$qb = $this->getClientUpdateSql();
		$qb->set('token', $qb->createNamedParameter($client->getToken()));
		$qb->set('auth_code', $qb->createNamedParameter(''));

		$qb->limitToId($client->getId());

		$qb->execute();
	}


	/**
	 * @param SocialClient $client
	 */
	public function updateTime(SocialClient $client) {
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

