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
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCP\DB\QueryBuilder\IQueryBuilder;


/**
 * Class LikesRequestBuilder
 *
 * @package OCA\Social\Db
 */
class LikesRequestBuilder extends CoreRequestBuilder {


	use TArrayTools;


	/**
	 * Base of the Sql Insert request
	 *
	 * @return IQueryBuilder
	 */
	protected function getLikesInsertSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->insert(self::TABLE_LIKES);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return IQueryBuilder
	 */
	protected function getLikesUpdateSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->update(self::TABLE_LIKES);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return IQueryBuilder
	 */
	protected function getLikesSelectSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select('l.id', 'l.type', 'l.actor_id', 'l.object_id', 'l.creation')
		   ->from(self::TABLE_LIKES, 'l');

		$this->defaultSelectAlias = 'l';

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return IQueryBuilder
	 */
	protected function countLikesSelectSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
		   ->from(self::TABLE_LIKES, 'l');

		$this->defaultSelectAlias = 'l';

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return IQueryBuilder
	 */
	protected function getLikesDeleteSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->delete(self::TABLE_LIKES);

		return $qb;
	}


	/**
	 * @param array $data
	 *
	 * @return Like
	 */
	protected function parseLikesSelectSql($data): Like {
		$like = new Like();
		$like->importFromDatabase($data);

		try {
			$actor = $this->parseCacheActorsLeftJoin($data);
			$actor->setCompleteDetails(true);

			$like->setActor($actor);
		} catch (InvalidResourceException $e) {
		}

		return $like;
	}

}

