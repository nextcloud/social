<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use Exception;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Tools\Traits\TArrayTools;
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
			->setValue('accepted', $qb->createNamedParameter(($follow->isAccepted()) ? '1' : '0'))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($follow->getActorId())))
			->setValue('object_id_prim', $qb->createNamedParameter($qb->prim($follow->getObjectId())))
			->setValue('follow_id_prim', $qb->createNamedParameter($qb->prim($follow->getFollowId())));

		try {
			$qb->setValue(
				'creation',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		$qb->generatePrimaryKey($follow->getId());
		$qb->executeStatement();
	}


	/**
	 * Create a self-follow (Loopback) entry for a local actor.
	 *
	 * This ensures the user appears in their own home timeline.
	 * Uses INSERT IGNORE to handle duplicate calls safely.
	 *
	 * @param Person $actor
	 */
	public function generateLoopbackAccount(Person $actor) {
		if ($this->isLoppbackExisting($actor->getId())) {
			return;  // Already has a loopback, skip
		}

		$qb = $this->getFollowsInsertSql();
		$qb->setValue('id', $qb->createNamedParameter($actor->getId()))
			->setValue('actor_id', $qb->createNamedParameter($actor->getId()))
			->setValue('type', $qb->createNamedParameter('Loopback'))
			->setValue('object_id', $qb->createNamedParameter($actor->getId()))
			->setValue('follow_id', $qb->createNamedParameter($actor->getId()))
			->setValue('accepted', $qb->createNamedParameter('1'))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actor->getId())))
			->setValue('object_id_prim', $qb->createNamedParameter($qb->prim($actor->getId())))
			->setValue('follow_id_prim', $qb->createNamedParameter($qb->prim($actor->getId())));

		try {
			$qb->setValue(
				'creation',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		$qb->generatePrimaryKey($actor->getId());
		$qb->executeStatement();
	}


	/**
	 * Check if a loopback (self-follow) already exists for this actor.
	 *
	 * @param string $actorId
	 *
	 * @return bool
	 */
	private function isLoppbackExisting(string $actorId): bool {
		try {
			$this->getByPersons($actorId, $actorId);
			return true;
		} catch (FollowNotFoundException $e) {
			return false;
		}
	}


	/**
	 * Mark a follow as accepted.
	 *
	 * Critical: remote servers embed the Follow in their Accept with THEIR id,
	 * not ours. Match by actor+object pair instead.
	 *
	 * @param Follow $follow
	 */
	public function accepted(Follow $follow) {
		$qb = $this->getFollowsUpdateSql();
		$qb->set('accepted', $qb->createNamedParameter('1'));
		$qb->limitToActorIdPrim($qb->prim($follow->getActorId()));
		$qb->limitToObjectIdPrim($qb->prim($follow->getObjectId()));

		$qb->executeStatement();
	}


	/**
	 * @return Follow[]
	 */
	public function getAll(): array {
		$qb = $this->getFollowsSelectSql();

		return $this->getFollowsFromRequest($qb);
	}


	/**
	 * @param string $actorId
	 * @param string $remoteActorId
	 *
	 * @return Follow
	 * @throws FollowNotFoundException
	 */
	public function getByPersons(string $actorId, string $remoteActorId): Follow {
		$qb = $this->getFollowsSelectSql();
		$qb->limitToActorIdPrim($qb->prim($actorId));
		$qb->limitToObjectIdPrim($qb->prim($remoteActorId));

		return $this->getFollowFromRequest($qb);
	}


	/**
	 * @param string $actorId
	 *
	 * @return int
	 */
	public function countFollowers(string $actorId): int {
		$qb = $this->countFollowsSelectSql();
		$qb->limitToObjectIdPrim($qb->prim($actorId));
		$qb->limitToType(Follow::TYPE);
		$qb->limitToAccepted(true);

		$cursor = $qb->executeQuery();
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
		$qb->limitToActorIdPrim($qb->prim($actorId));
		$qb->limitToType(Follow::TYPE);
		$qb->limitToAccepted(true);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}


	/**
	 * @return int
	 */
	public function countFollows() {
		$qb = $this->countFollowsSelectSql();

		$cursor = $qb->executeQuery();
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
		$qb->limitToFollowId($followId);
		$qb->limitToAccepted(true);
		$this->leftJoinCacheActors($qb, 'actor_id');

		return $this->getFollowsFromRequest($qb);
	}


	/**
	 * @param string $actorId
	 *
	 * @return Follow[]
	 */
	public function getFollowersByActorId(string $actorId): array {
		$qb = $this->getFollowsSelectSql();
		$this->limitToObjectId($qb, $actorId);
		$this->limitToAccepted($qb, true);
		$this->leftJoinCacheActors($qb, 'actor_id');
		$this->leftJoinDetails($qb, 'id', 'ca');
		$qb->orderBy('f.creation', 'desc');

		// TODO: pagination

		return $this->getFollowsFromRequest($qb);
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

		return $this->getFollowsFromRequest($qb);
	}


	/**
	 * @param string $followId
	 *
	 * @return Follow[]
	 */
	public function getFollowersByFollowId(string $followId): array {
		$qb = $this->getFollowsSelectSql();
		$this->limitToFollowId($qb, $followId);
		$this->limitToAccepted($qb, true);
		$this->leftJoinAccounts($qb, 'actor_id');

		return $this->getFollowsFromRequest($qb);
	}


	/**
	 * @param Follow $follow
	 */
	public function delete(Follow $follow) {
		$qb = $this->getFollowsDeleteSql();
		$this->limitToIdString($qb, $follow->getId());

		$qb->executeStatement();
	}

	/**
	 * @param Follow $follow
	 */
	public function deleteByPersons(Follow $follow) {
		$qb = $this->getFollowsDeleteSql();
		$this->limitToActorId($qb, $follow->getActorId());
		$this->limitToObjectId($qb, $follow->getObjectId());

		$qb->executeStatement();
	}

	/**
	 * @param string $actorId
	 */
	public function deleteRelatedId(string $actorId) {
		$qb = $this->getFollowsDeleteSql();
		$orX = $qb->expr()->orX(
			$qb->exprLimitToDBField('actor_id_prim', $qb->prim($actorId)),
			$qb->exprLimitToDBField('object_id_prim', $qb->prim($actorId))
		);
		$qb->where($orX);
		$qb->executeStatement();
	}

	/**
	 * @param string $id
	 */
	public function deleteById(string $id) {
		$qb = $this->getFollowsDeleteSql();
		$this->limitToIdString($qb, $id);

		$qb->executeStatement();
	}


	/**
	 * @param string $actorId
	 * @param Person $new
	 */
	public function moveAccountFollowers(string $actorId, Person $new): void {
		$qb = $this->getFollowsUpdateSql();
		$qb->set('object_id', $qb->createNamedParameter($new->getId()))
			->set('object_id_prim', $qb->createNamedParameter($qb->prim($new->getId())))
			->set('follow_id', $qb->createNamedParameter($new->getFollowers()))
			->set('follow_id_prim', $qb->createNamedParameter($qb->prim($new->getFollowers())));

		$qb->limitToObjectIdPrim($qb->prim($actorId));

		$qb->executeStatement();
	}


	/**
	 * @param string $actorId
	 * @param Person $new
	 */
	public function moveAccountFollowing(string $actorId, Person $new): void {
		$qb = $this->getFollowsUpdateSql();
		$qb->set('actor_id', $qb->createNamedParameter($new->getId()))
			->set('actor_id_prim', $qb->createNamedParameter($qb->prim($new->getId())));

		$qb->limitToActorIdPrim($qb->prim($actorId));

		$qb->executeStatement();
	}


	/**
	 * Returns everything related to a list of actorIds.
	 * Looking at actor_id_prim and object_id_prim.
	 *
	 * @param array $actorIds
	 *
	 * @return Follow[]
	 */
	public function getFollows(array $actorIds): array {
		$qb = $this->getFollowsSelectSql();
		$qb->limitToType(Follow::TYPE);

		$prims = [];
		foreach ($actorIds as $actorId) {
			$prims[] = $qb->prim($actorId);
		}

		$orX = $qb->expr()->orX(
			$qb->exprLimitInArray('actor_id_prim', $prims),
			$qb->exprLimitInArray('object_id_prim', $prims)
		);

		return $this->getFollowsFromRequest($qb);
	}
}
