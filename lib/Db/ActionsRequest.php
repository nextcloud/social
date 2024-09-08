<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use Exception;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Tools\Traits\TArrayTools;
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
	 */
	public function save(ACore $like): void {
		$qb = $this->getActionsInsertSql();
		$qb->setValue('id', $qb->createNamedParameter($like->getId()))
		   ->setValue('id_prim', $qb->createNamedParameter($qb->prim($like->getId())))
		   ->setValue('actor_id', $qb->createNamedParameter($like->getActorId()))
		   ->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($like->getActorId())))
		   ->setValue('type', $qb->createNamedParameter($like->getType()))
		   ->setValue('object_id', $qb->createNamedParameter($like->getObjectId()))
		   ->setValue('object_id_prim', $qb->createNamedParameter($qb->prim($like->getObjectId())));

		try {
			$qb->setValue(
				'creation',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		$qb->executeStatement();
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
		$qb->limitToActorIdPrim($qb->prim($actorId));
		$qb->limitToObjectIdPrim($qb->prim($objectId));
		$qb->limitToType($type);

		return $this->getActionFromRequest($qb);
	}


	/**
	 * @param ACore $item
	 *
	 * @return ACore
	 * @throws ActionDoesNotExistException
	 */
	public function getActionFromItem(ACore $item): ACore {
		$qb = $this->getActionsSelectSql();

		$qb->limitToActorIdPrim($qb->prim($item->getActorId()));
		$qb->limitToObjectIdPrim($qb->prim($item->getObjectId()));
		$qb->limitToType($item->getType());

		return $this->getActionFromRequest($qb);
	}


	/**
	 * @param string $objectId
	 * @param string $type
	 *
	 * @return int
	 */
	public function countActions(string $objectId, string $type): int {
		$qb = $this->countActionsSelectSql();
		$qb->limitToObjectIdPrim($qb->prim($objectId));
		$qb->limitToType($type);

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
		$qb->limitToObjectIdPrim($qb->prim($objectId));
		$this->leftJoinCacheActors($qb, 'actor_id');

		return $this->getActionsFromRequest($qb);
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


	public function deleteByActor(string $actorId): void {
		$qb = $this->getActionsDeleteSql();
		$qb->limitToDBField('actor_id_prim', $qb->prim($actorId));

		$qb->executeStatement();
	}


	public function moveAccount(string $actorId, string $newId): void {
		$qb = $this->getActionsUpdateSql();
		$qb->set('actor_id', $qb->createNamedParameter($newId))
		   ->set('actor_id_prim', $qb->createNamedParameter($qb->prim($newId)));

		$qb->limitToDBField('actor_id_prim', $qb->prim($actorId));

		$qb->executeStatement();
	}
}
