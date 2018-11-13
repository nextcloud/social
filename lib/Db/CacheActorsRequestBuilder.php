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
use OCA\Social\Model\ActivityPub\Cache\CacheActor;
use OCA\Social\Model\ActivityPub\Person;
use OCP\DB\QueryBuilder\IQueryBuilder;

class CacheActorsRequestBuilder extends CoreRequestBuilder {


	use TArrayTools;


	/**
	 * Base of the Sql Insert request
	 *
	 * @return IQueryBuilder
	 */
	protected function getCacheActorsInsertSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->insert(self::TABLE_CACHE_ACTORS);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return IQueryBuilder
	 */
	protected function getCacheActorsUpdateSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->update(self::TABLE_CACHE_ACTORS);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return IQueryBuilder
	 */
	protected function getCacheActorsSelectSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select(
			'ca.id', 'ca.account', 'ca.following', 'ca.followers', 'ca.inbox',
			'ca.shared_inbox', 'ca.outbox', 'ca.featured', 'ca.url',
			'ca.preferred_username', 'ca.name', 'ca.summary',
			'ca.public_key', 'ca.creation'
		)
		   ->from(self::TABLE_CACHE_ACTORS, 'ca');

		$this->defaultSelectAlias = 'ca';

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return IQueryBuilder
	 */
	protected function getCacheActorsDeleteSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->delete(self::TABLE_CACHE_ACTORS);

		return $qb;
	}


	/**
	 * @param array $data
	 *
	 * @return Person
	 */
	protected function parseCacheActorsSelectSql(array $data): Person {
		$actor = new Person();
		$actor->import($data);

		return $actor;
	}

}

