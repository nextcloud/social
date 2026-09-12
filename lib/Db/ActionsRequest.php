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
	 * The action a URI names — a Like, a Block, a pin.
	 *
	 * Only a peer that sends the object of an `Undo` as a bare link needs
	 * this: the id is then all there is to go on.
	 *
	 * @throws ActionDoesNotExistException
	 */
	public function getById(string $id): ACore {
		if ($id === '') {
			throw new ActionDoesNotExistException('empty action id');
		}

		$qb = $this->getActionsSelectSql();
		$this->limitToIdPrimString($qb, $id);

		return $this->getActionFromRequest($qb);
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
		$this->limitToPrim($qb, 'actor_id_prim', $actorId);
		$this->limitToPrim($qb, 'object_id_prim', $objectId);
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

		$this->limitToPrim($qb, 'actor_id_prim', $item->getActorId());
		$this->limitToPrim($qb, 'object_id_prim', $item->getObjectId());
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
		$this->limitToPrim($qb, 'object_id_prim', $objectId);
		$qb->limitToType($type);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * Every action of one type by one actor, newest first.
	 *
	 * @return ACore[]
	 */
	public function getActionsByActor(string $actorId, string $type, int $limit = 0): array {
		$qb = $this->getActionsSelectSql();
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}
		$this->limitToPrim($qb, 'actor_id_prim', $actorId);
		$qb->limitToType($type);
		$qb->orderBy('a.creation', 'desc');

		return $this->getActionsFromRequest($qb);
	}

	/**
	 * Who did one thing to one post, newest first: the rows behind Mastodon's
	 * `favourited_by` and `reblogged_by`.
	 *
	 * The actor of each row is joined in, because the answer is a page of
	 * accounts rather than of actions — a row whose actor this instance has
	 * never cached has nothing to show and is left out by the caller.
	 *
	 * @return ACore[]
	 */
	public function getActionsOnObject(string $objectId, string $type, int $limit = 0, int $offset = 0): array {
		$qb = $this->getActionsSelectSql();
		$this->limitToPrim($qb, 'object_id_prim', $objectId);
		$qb->limitToType($type);
		$this->leftJoinCacheActors($qb, 'actor_id');
		$qb->orderBy('a.creation', 'desc');
		if ($limit > 0) {
			$qb->setMaxResults($limit);
			$qb->setFirstResult($offset);
		}

		return $this->getActionsFromRequest($qb);
	}

	/**
	 * Removes one action, addressed the way it is looked up.
	 */
	public function deleteAction(string $actorId, string $objectId, string $type): void {
		$qb = $this->getActionsDeleteSql();
		$qb->limitToDBField('actor_id_prim', $qb->prim($actorId));
		$qb->limitToDBField('object_id_prim', $qb->prim($objectId));
		$qb->limitToType($type);

		$qb->executeStatement();
	}

	/**
	 * @param string $objectId
	 *
	 * @return Like[]
	 */
	public function getByObjectId(string $objectId, int $limit = 0): array {
		$qb = $this->getActionsSelectSql();
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}
		$this->limitToPrim($qb, 'object_id_prim', $objectId);
		$this->leftJoinCacheActors($qb, 'actor_id');

		return $this->getActionsFromRequest($qb);
	}

	/**
	 * @param ACore $item
	 */
	public function delete(ACore $item) {
		$qb = $this->getActionsDeleteSql();
		$this->limitToIdPrimString($qb, $item->getId());
		$qb->limitToType($item->getType());

		$qb->executeStatement();
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
