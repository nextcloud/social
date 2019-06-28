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
use OCA\Social\Exceptions\LikeDoesNotExistException;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCP\DB\QueryBuilder\IQueryBuilder;


/**
 * Class LikesRequest
 *
 * @package OCA\Social\Db
 */
class LikesRequest extends LikesRequestBuilder {


	use TArrayTools;


	/**
	 * Insert a new Note in the database.
	 *
	 * @param Like $like
	 */
	public function save(Like $like) {
		$qb = $this->getLikesInsertSql();
		$qb->setValue('id', $qb->createNamedParameter($like->getId()))
		   ->setValue('actor_id', $qb->createNamedParameter($like->getActorId()))
		   ->setValue('type', $qb->createNamedParameter($like->getType()))
		   ->setValue('object_id', $qb->createNamedParameter($like->getObjectId()));

		try {
			$qb->setValue(
				'creation',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		$this->generatePrimaryKey($qb, $like->getId());

		$qb->execute();
	}


	/**
	 * @param string $actorId
	 * @param string $objectId
	 *
	 * @return Like
	 * @throws LikeDoesNotExistException
	 */
	public function getLike(string $actorId, string $objectId): Like {
		$qb = $this->getLikesSelectSql();
		$this->limitToActorId($qb, $actorId);
		$this->limitToObjectId($qb, $objectId);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();
		if ($data === false) {
			throw new LikeDoesNotExistException();
		}

		return $this->parseLikesSelectSql($data);
	}


	/**
	 * @param string $objectId
	 *
	 * @return int
	 */
	public function countLikes(string $objectId): int {
		$qb = $this->countLikesSelectSql();
		$this->limitToObjectId($qb, $objectId);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}


	/**
	 * @param string $objectId
	 *
	 * @return Like[]
	 */
	public function getByObjectId(string $objectId): array {
		$qb = $this->getLikesSelectSql();
		$this->limitToObjectId($qb, $objectId);
		$this->leftJoinCacheActors($qb, 'actor_id');

		$likes = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$likes[] = $this->parseLikesSelectSql($data);
		}
		$cursor->closeCursor();

		return $likes;
	}


	/**
	 * @param Like $like
	 */
	public function delete(Like $like) {
		$qb = $this->getLikesDeleteSql();
		$this->limitToIdString($qb, $like->getId());

		$qb->execute();
	}


	/**
	 * @param string $objectId
	 */
	public function deleteLikes(string $objectId) {
		$qb = $this->getLikesDeleteSql();
		$this->limitToObjectId($qb, $objectId);

		$qb->execute();
	}

}

