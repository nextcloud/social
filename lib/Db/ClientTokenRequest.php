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
use OCA\Social\Exceptions\ClientTokenDoesNotExistException;
use OCA\Social\Model\Client\ClientToken;
use OCP\DB\QueryBuilder\IQueryBuilder;


/**
 * Class ClientAuthRequest
 *
 * @package OCA\Social\Db
 */
class ClientTokenRequest extends ClientTokenRequestBuilder {


	use TArrayTools;


	/**
	 * @param ClientToken $clientToken
	 */
	public function save(ClientToken $clientToken) {
		$now = new DateTime('now');
		$clientToken->setCreation($now->getTimestamp());

		$qb = $this->getClientTokenInsertSql();
		$qb->setValue('id', $qb->createNamedParameter($clientToken->getId()))
		   ->setValue('auth_id', $qb->createNamedParameter($clientToken->getAuthId()))
		   ->setValue('token', $qb->createNamedParameter($clientToken->getToken()))
		   ->setValue('last_update', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
		   ->setValue('creation', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE));

		$qb->execute();
	}


	/**
	 * @param string $code
	 *
	 * @return ClientToken
	 * @throws ClientTokenDoesNotExistException
	 */
	public function getByToken(string $code): ClientToken {
		$qb = $this->getClientTokenSelectSql();
		$qb->limitToDBField('token', $code);

		return $this->getClientTokenFromRequest($qb);
	}

}

