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


use Exception;
use OCA\Social\Exceptions\FollowDoesNotExistException;
use OCA\Social\Model\ActivityPub\Follow;
use OCA\Social\Model\ActivityPub\Person;


/**
 * Class FollowsRequest
 *
 * @package OCA\Social\Db
 */
class FollowsRequest extends FollowsRequestBuilder {


	/**
	 * Insert a new Note in the database.
	 *
	 * @param Follow $follow
	 */
	public function save(Follow $follow) {
		$qb = $this->getFollowsInsertSql();
		$qb->setValue('id', $qb->createNamedParameter($follow->getId()))
		   ->setValue('actor_id', $qb->createNamedParameter($follow->getActorId()))
		   ->setValue('object_id', $qb->createNamedParameter($follow->getObjectId()));

		$qb->execute();
	}


	/**
	 * @param Person $actor
	 * @param Person $remote
	 *
	 * @return Follow
	 * @throws FollowDoesNotExistException
	 */
	public function getByPersons(Person $actor, Person $remote) {
		$qb = $this->getFollowsSelectSql();
		$this->limitToActorId($qb, $actor->getId());
		$this->limitToObjectId($qb, $remote->getId());

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new FollowDoesNotExistException();
		}

		return $this->parseFollowsSelectSql($data);
	}


	/**
	 * @param Follow $follow
	 */
	public function delete(Follow $follow) {
		$qb = $this->getFollowsDeleteSql();
		$this->limitToIdString($qb, $follow->getId());

		$qb->execute();
	}


}

