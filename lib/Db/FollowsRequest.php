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
use OCA\Social\Exceptions\FollowDoesNotExistException;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCP\DB\QueryBuilder\IQueryBuilder;


/**
 * Class FollowsRequest
 *
 * @package OCA\Social\Db
 */
class FollowsRequest extends FollowsRequestBuilder {


	use TArrayTools;


	/**
	 * Insert a new Note in the database.
	 *
	 * @param Follow $follow
	 */
	public function save(Follow $follow) {
		$qb = $this->getFollowsInsertSql();
		$qb->setValue('id', $qb->createNamedParameter($follow->getId()))
		   ->setValue('actor_id', $qb->createNamedParameter($follow->getActorId()))
		   ->setValue('type', $qb->createNamedParameter($follow->getType()))
		   ->setValue('object_id', $qb->createNamedParameter($follow->getObjectId()))
		   ->setValue('follow_id', $qb->createNamedParameter($follow->getFollowId()))
		   ->setValue(
			   'creation',
			   $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
		   );

		$qb->execute();
	}


	/**
	 * @param Follow $follow
	 */
	public function accepted(Follow $follow) {
		$qb = $this->getFollowsUpdateSql();
		$qb->set('accepted', $qb->createNamedParameter('1'));
		$this->limitToIdString($qb, $follow->getId());
		$this->limitToActorId($qb, $follow->getActorId());
		$this->limitToObjectId($qb, $follow->getObjectId());

		$qb->execute();
	}


	/**
	 * @param string $actorId
	 * @param string $remoteActorId
	 *
	 * @return Follow
	 * @throws FollowDoesNotExistException
	 */
	public function getByPersons(string $actorId, string $remoteActorId) {
		$qb = $this->getFollowsSelectSql();
		$this->limitToActorId($qb, $actorId);
		$this->limitToObjectId($qb, $remoteActorId);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();
		if ($data === false) {
			throw new FollowDoesNotExistException();
		}

		return $this->parseFollowsSelectSql($data);
	}


	/**
	 * @param string $actorId
	 *
	 * @return int
	 */
	public function countFollowers(string $actorId): int {
		$qb = $this->countFollowsSelectSql();
		$this->limitToObjectId($qb, $actorId);
		$this->limitToAccepted($qb, true);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}


	/**
	 * @param string $actorId
	 *
	 * @return int
	 */
	public function countFollowing(string $actorId): int {
		$qb = $this->countFollowsSelectSql();
		$this->limitToActorId($qb, $actorId);
		$this->limitToAccepted($qb, true);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}


	/**
	 * @return int
	 */
	public function countFollows() {
		$qb = $this->countFollowsSelectSql();

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}


	/**
	 * @param string $followId
	 *
	 * @return Follow[]
	 */
	public function getByFollowId(string $followId): array {
		$qb = $this->getFollowsSelectSql();
		$this->limitToFollowId($qb, $followId);
		$this->limitToAccepted($qb, true);
		$this->leftJoinCacheActors($qb, 'actor_id');

		$follows = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$follows[] = $this->parseFollowsSelectSql($data);
		}
		$cursor->closeCursor();

		return $follows;
	}


	/**
	 * @param string $actorId
	 *
	 * @return Follow[]
	 */
	public function getFollowersByActorId(string $actorId): array {
		$qb = $this->getFollowsSelectSql();
		$this->limitToOBjectId($qb, $actorId);
		$this->limitToAccepted($qb, true);
		$this->leftJoinCacheActors($qb, 'actor_id');
		$this->leftJoinDetails($qb, 'id', 'ca');
		$qb->orderBy('f.creation', 'desc');

		$follows = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$follows[] = $this->parseFollowsSelectSql($data);
		}
		$cursor->closeCursor();

		return $follows;
	}


	/**
	 * @param string $actorId
	 *
	 * @return Follow[]
	 */
	public function getFollowingByActorId(string $actorId): array {
		$qb = $this->getFollowsSelectSql();
		$this->limitToActorId($qb, $actorId);
		$this->limitToAccepted($qb, true);
		$this->leftJoinCacheActors($qb, 'object_id');
		$this->leftJoinDetails($qb, 'id', 'ca');
		$qb->orderBy('f.creation', 'desc');

		$follows = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$follows[] = $this->parseFollowsSelectSql($data);
		}
		$cursor->closeCursor();

		return $follows;
	}


	/**
	 * @param Follow $follow
	 */
	public function delete(Follow $follow) {
		$qb = $this->getFollowsDeleteSql();
		$this->limitToIdString($qb, $follow->getId());

		$qb->execute();
	}


	/**
	 * @param Follow $follow
	 */
	public function deleteByPersons(Follow $follow) {
		$qb = $this->getFollowsDeleteSql();
		$this->limitToActorId($qb, $follow->getActorId());
		$this->limitToObjectId($qb, $follow->getObjectId());

		$qb->execute();
	}


}

