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
use Exception;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\Client\SocialClient;


/**
 * Class ClientRequestBuilder
 *
 * @package OCA\Social\Db
 */
class ClientRequestBuilder extends CoreRequestBuilder {


	use TArrayTools;


	/**
	 * Base of the Sql Insert request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getClientInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_CLIENT);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getClientUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CLIENT);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getClientSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select(
			'cl.id', 'cl.app_name', 'cl.app_website', 'cl.app_redirect_uris', 'cl.app_client_id',
			'cl.app_client_secret', 'cl.app_scopes', 'cl.auth_scopes', 'cl.auth_account', 'cl.auth_user_id',
			'cl.auth_code', 'cl.token', 'cl.last_update', 'cl.creation'
		)
		   ->from(self::TABLE_CLIENT, 'cl');

		$this->defaultSelectAlias = 'cl';
		$qb->setDefaultSelectAlias('cl');

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getClientDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_CLIENT);

		return $qb;
	}


	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return SocialClient
	 * @throws ClientNotFoundException
	 */
	public function getClientFromRequest(SocialQueryBuilder $qb): SocialClient {
		/** @var SocialClient $result */
		try {
			$result = $qb->getRow([$this, 'parseClientSelectSql']);
		} catch (RowNotFoundException $e) {
			throw new ClientNotFoundException($e->getMessage());
		}

		return $result;
	}


	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return SocialClient[]
	 */
	public function getClientsFromRequest(SocialQueryBuilder $qb): array {
		/** @var SocialClient[] $result */
		$result = $qb->getRows([$this, 'parseClientSelectSql']);

		return $result;
	}


	/**
	 * @param array $data
	 *
	 * @return SocialClient
	 * @throws Exception
	 */
	public function parseClientSelectSql(array $data): SocialClient {
		$item = new SocialClient();
		$item->importFromDatabase($data);

		return $item;
	}

}

