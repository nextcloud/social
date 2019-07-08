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
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCP\DB\QueryBuilder\IQueryBuilder;


/**
 * Class ActionsRequest
 *
 * @package OCA\Social\Db
 */
class ActionsRequest extends ActionsRequestBuilder {


	use TArrayTools;


	/**
	 * Insert a new Note in the database.
	 *
	 * @param ACore $like
	 */
	public function save(ACore $like) {
		$qb = $this->getActionsInsertSql();
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
	 * @param string $type
	 *
	 * @return ACore
	 * @throws ActionDoesNotExistException
	 */
	public function getAction(string $actorId, string $objectId, string $type): ACore {
		$qb = $this->getActionsSelectSql();
		$this->limitToActorId($qb, $actorId);
		$this->limitToObjectId($qb, $objectId);
		$this->limitToType($qb, $type);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();
		if ($data === false) {
			throw new ActionDoesNotExistException();
		}

		return $this->parseActionsSelectSql($data);
	}


	/**
	 * @param ACore $item
	 *
	 * @return ACore
	 * @throws ActionDoesNotExistException
	 */
	public function getActionFromItem(ACore $item): ACore {
		$qb = $this->getActionsSelectSql();
		$this->limitToActorId($qb, $item->getActorId());
		$this->limitToObjectId($qb, $item->getObjectId());
		$this->limitToType($qb, $item->getType());

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();
		if ($data === false) {
			throw new ActionDoesNotExistException();
		}

		return $this->parseActionsSelectSql($data);
	}


	/**
	 * @param string $objectId
	 * @param string $type
	 *
	 * @return int
	 */
	public function countActions(string $objectId, string $type): int {
		$qb = $this->countActionsSelectSql();
		$this->limitToObjectId($qb, $objectId);
		$this->limitToType($qb, $type);

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
		$qb = $this->getActionsSelectSql();
		$this->limitToObjectId($qb, $objectId);
		$this->leftJoinCacheActors($qb, 'actor_id');

		$likes = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$likes[] = $this->parseActionsSelectSql($data);
		}
		$cursor->closeCursor();

		return $likes;
	}


	/**
	 * @param ACore $item
	 */
	public function delete(ACore $item) {
		$qb = $this->getActionsDeleteSql();
		$this->limitToIdString($qb, $item->getId());
		$this->limitToType($qb, $item->getType());

		$qb->execute();
	}


	/**
	 * @param string $objectId
	 */
	public function deleteLikes(string $objectId) {
		$qb = $this->getActionsDeleteSql();
		$this->limitToObjectId($qb, $objectId);

		$qb->execute();
	}

}

