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
use OCA\Social\Exceptions\ClientAppDoesNotExistException;
use OCA\Social\Model\ActivityStream\ClientApp;
use OCP\DB\QueryBuilder\IQueryBuilder;


/**
 * Class ActionsRequest
 *
 * @package OCA\Social\Db
 */
class ClientAppRequest extends ClientAppRequestBuilder {


	use TArrayTools;


	/**
	 * Insert a new OAuth client in the database.
	 *
	 * @param ClientApp $clientApp
	 */
	public function save(ClientApp $clientApp) {
		$qb = $this->getClientAppInsertSql();
		$qb->setValue('name', $qb->createNamedParameter($clientApp->getName()))
		   ->setValue('website', $qb->createNamedParameter($clientApp->getWebsite()))
		   ->setValue('redirect_uri', $qb->createNamedParameter($clientApp->getRedirectUri()))
		   ->setValue('client_id', $qb->createNamedParameter($clientApp->getClientId()))
		   ->setValue('client_secret', $qb->createNamedParameter($clientApp->getClientSecret()));

		try {
			$qb->setValue(
				'creation',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		$qb->execute();

		$clientApp->setId($qb->getLastInsertId());
	}


	/**
	 * @param string $clientId
	 * @param string $account
	 */
	public function assignAccount(string $clientId, string $account): void {
		$qb = $this->getClientAppUpdateSql();
		$qb->set('account', $qb->createNamedParameter($account));

		$qb->limitToClientId($clientId);
		$qb->limitToAccount('');

		$qb->execute();
	}


	/**
	 * @param string $clientId
	 *
	 * @return ClientApp
	 * @throws ClientAppDoesNotExistException
	 */
	public function getByClientId(string $clientId): ClientApp {
		$qb = $this->getClientAppSelectSql();

		$qb->limitToClientId($clientId);

		return $this->getClientAppFromRequest($qb);
	}

}

