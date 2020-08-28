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
use OCA\Social\Exceptions\ClientAuthDoesNotExistException;
use OCA\Social\Model\ActivityStream\ClientApp;
use OCA\Social\Model\Client\ClientAuth;
use OCP\DB\QueryBuilder\IQueryBuilder;


/**
 * Class ClientAuthRequest
 *
 * @package OCA\Social\Db
 */
class ClientAuthRequest extends ClientAuthRequestBuilder {


	use TArrayTools;


	/**
	 * @param ClientAuth $clientAuth
	 */
	public function save(ClientAuth $clientAuth) {
		$qb = $this->getClientAuthInsertSql();

		$now = new DateTime('now');
		$qb->setValue('client_id', $qb->createNamedParameter($clientAuth->getClientId()))
		   ->setValue('account', $qb->createNamedParameter($clientAuth->getAccount()))
		   ->setValue('code', $qb->createNamedParameter($clientAuth->getCode()))
		   ->setValue('user_id', $qb->createNamedParameter($clientAuth->getUserId()))
		   ->setValue('last_update', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
		   ->setValue('creation', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE));

		$qb->execute();
	}


	/**
	 * @param string $code
	 *
	 * @return ClientAuth
	 * @throws ClientAuthDoesNotExistException
	 */
	public function getByCode(string $code): ClientAuth {
		$qb = $this->getClientAuthSelectSql();
		$qb->limitToDBField('code', $code);

		return $this->getClientAuthFromRequest($qb);
	}


	/**
	 * @param string $token
	 *
	 * @return ClientAuth
	 * @throws ClientAuthDoesNotExistException
	 */
	public function getByToken(string $token): ClientAuth {
		$qb = $this->getClientAuthSelectSql();
		$qb->leftJoinClientToken('clt');
		$qb->limitToToken($token, 'clt');

		return $this->getClientAuthFromRequest($qb);
	}

}

