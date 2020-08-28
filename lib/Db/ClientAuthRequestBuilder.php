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


use daita\MySmallPhpTools\Exceptions\RowNotFoundException;
use daita\MySmallPhpTools\Traits\TArrayTools;
use OCA\Social\Exceptions\ClientAuthDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityStream\ClientApp;
use OCA\Social\Model\Client\ClientAuth;


/**
 * Class ClientAppRequestBuilder
 *
 * @package OCA\Social\Db
 */
class ClientAuthRequestBuilder extends CoreRequestBuilder {


	use TArrayTools;


	/**
	 * Base of the Sql Insert request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getClientAuthInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_CLIENT_AUTH);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getClientAuthUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CLIENT_AUTH);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getClientAuthSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select('cla.id', 'cla.client_id', 'cla.account', 'cla.user_id', 'cla.code')
		   ->from(self::TABLE_CLIENT_AUTH, 'cla');

		$this->defaultSelectAlias = 'cla';
		$qb->setDefaultSelectAlias('cla');

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getClientAuthDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_CLIENT_AUTH);

		return $qb;
	}


	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return ClientAuth
	 * @throws ClientAuthDoesNotExistException
	 */
	public function getClientAuthFromRequest(SocialQueryBuilder $qb): ClientAuth {
		/** @var ClientAuth $result */
		try {
			$result = $qb->getRow([$this, 'parseClientAuthSelectSql']);
		} catch (RowNotFoundException $e) {
			throw new ClientAuthDoesNotExistException($e->getMessage());
		}

		return $result;
	}


	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return ClientAuth[]
	 */
	public function getClientAuthsFromRequest(SocialQueryBuilder $qb): array {
		/** @var ClientAuth[] $result */
		$result = $qb->getRows([$this, 'parseClientAuthSelectSql']);

		return $result;
	}


	/**
	 * @param array $data
	 *
	 * @param SocialQueryBuilder $qb
	 *
	 * @return ClientAuth
	 */
	public function parseClientAuthSelectSql($data, SocialQueryBuilder $qb): ClientAuth {
		$item = new ClientAuth();
		$item->importFromDatabase($data);

		try {
			$item->setClientToken($qb->parseLeftJoinClientToken($data));
		} catch (InvalidResourceException $e) {
		}

		return $item;
	}

}

