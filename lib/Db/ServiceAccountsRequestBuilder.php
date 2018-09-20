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


use daita\Traits\TArrayTools;
use OCA\Social\Model\Service;
use OCA\Social\Model\ServiceAccount;
use OCP\DB\QueryBuilder\IQueryBuilder;

class ServiceAccountsRequestBuilder extends CoreRequestBuilder {


	use TArrayTools;


	/**
	 * Base of the Sql Insert request
	 *
	 * @return IQueryBuilder
	 */
	protected function getAccountsInsertSql() {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->insert(self::TABLE_ACCOUNTS);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return IQueryBuilder
	 */
	protected function getAccountsUpdateSql() {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->update(self::TABLE_ACCOUNTS);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return IQueryBuilder
	 */
	protected function getAccountsSelectSql() {
		$qb = $this->dbConnection->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select(
			'a.id', 'a.service_id', 'a.user_id', 'a.account', 'a.account_id', 'a.status', 'a.auth',
			'a.config', 'a.creation'
		)
		   ->from(self::TABLE_ACCOUNTS, 'a');

		$this->defaultSelectAlias = 'a';

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return IQueryBuilder
	 */
	protected function getAccountsDeleteSql() {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->delete(self::TABLE_ACCOUNTS);

		return $qb;
	}


	/**
	 * @param array $data
	 *
	 * @return ServiceAccount
	 */
	protected function parseAccountsSelectSql($data) {

		$service = new Service($this->getInt('service_id', $data));
		$service->setAddress($this->get('service_address', $data, ''))
				->setStatus($this->getInt('service_status', $data, 0))
				->setConfigAll(json_decode($this->get('service_config', $data, '[]'), true))
				->setType($this->get('service_type', $data, ''));

		$account = new ServiceAccount(intval($data['id']));
		$account->setService($service)
				->setUserId($data['user_id'])
				->setAccount($this->get('account', $data, ''))
				->setAccountId($this->getInt('account_id', $data, 0))
				->setStatus($this->getInt('status', $data, 0))
				->setAuthAll(json_decode($this->get('auth', $data, '[]'), true))
				->setConfigAll(json_decode($this->get('config', $data, '[]'), true))
				->setCreation($this->getInt('creation', $data, 0));

		return $account;
	}

}

