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
use OCA\Social\Exceptions\InvalidResourceException;
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
		$this->limitToPrim($qb, 'actor_id_prim', $follow->getActorId());
		$this->limitToPrim($qb, 'object_id_prim', $follow->getObjectId());

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
	 * The follow a URI names.
	 *
	 * Only a peer that sends the object of an `Undo`/`Accept`/`Reject` as a
	 * bare link needs this: the id is then all there is to go on.
	 *
	 * @throws FollowNotFoundException
	 */
	public function getById(string $id): Follow {
		if ($id === '') {
			throw new FollowNotFoundException('empty follow id');
		}

		$qb = $this->getFollowsSelectSql();
		$this->limitToIdPrimString($qb, $id);

		return $this->getFollowFromRequest($qb);
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
		$this->limitToPrim($qb, 'actor_id_prim', $actorId);
		$this->limitToPrim($qb, 'object_id_prim', $remoteActorId);

		return $this->getFollowFromRequest($qb);
	}

	/**
	 * The follow rows between one actor and a *set* of others, both ways round.
	 *
	 * Answers the same two questions `getByPersons()` does — does the viewer
	 * follow them, do they follow the viewer — for a whole page in two queries
	 * rather than two per account.
	 *
	 * @param string[] $others
	 *
	 * @return array{following: array<string, Follow>, followedBy: array<string, Follow>}
	 */
	public function getBetweenMany(string $actorId, array $others): array {
		if ($others === []) {
			return ['following' => [], 'followedBy' => []];
		}

		return [
			'following' => $this->followsOneWay($actorId, $others, 'actor_id_prim', 'object_id_prim'),
			'followedBy' => $this->followsOneWay($actorId, $others, 'object_id_prim', 'actor_id_prim'),
		];
	}

	/**
	 * @param string[] $others
	 *
	 * @return array<string, Follow>
	 */
	private function followsOneWay(string $actorId, array $others, string $mine, string $theirs): array {
		$qb = $this->getFollowsSelectSql();
		$prims = array_map(static fn (string $id): string => $qb->prim($id), $others);
		$qb->andWhere($qb->expr()->eq('f.' . $mine, $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->in('f.' . $theirs, $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)));

		$follows = [];
		foreach ($this->getFollowsFromRequest($qb) as $follow) {
			// keyed by the *other* actor, whichever end of the row that is
			$other = ($mine === 'actor_id_prim') ? $follow->getObjectId() : $follow->getActorId();
			$follows[$other] = $follow;
		}

		return $follows;
	}

	/**
	 * @param string $actorId
	 *
	 * @return int
	 */
	public function countFollowers(string $actorId): int {
		$qb = $this->countFollowsSelectSql();
		$this->limitToPrim($qb, 'object_id_prim', $actorId);
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
	public function countPendingRequests(string $actorId): int {
		$qb = $this->countFollowsSelectSql();
		$this->limitToPrim($qb, 'object_id_prim', $actorId);
		$qb->limitToType(Follow::TYPE);
		$qb->limitToAccepted(false);

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
		$this->limitToPrim($qb, 'actor_id_prim', $actorId);
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
		$this->limitToPrim($qb, 'follow_id_prim', $followId);
		$qb->limitToAccepted(true);
		$this->leftJoinCacheActors($qb, 'actor_id');

		return $this->getFollowsFromRequest($qb);
	}

	/**
	 * The accepted followers of an actor, newest first.
	 *
	 * Each row is hydrated into a Follow with a Person and its details, so
	 * $limit/$offset are what keep this bounded — it serves the paged
	 * `followers` collection and the re-follow after a Move, both of which want
	 * the follows themselves. Federated delivery used to fan out over it and
	 * loaded a popular actor's whole follower list into memory for every post;
	 * it asks getFollowerInboxes() for the distinct inboxes instead.
	 *
	 * @return Follow[]
	 */
	public function getFollowersByActorId(string $actorId, int $limit = 0, int $offset = 0): array {
		$qb = $this->getFollowsSelectSql();
		$this->limitToPrim($qb, 'object_id_prim', $actorId);
		$this->limitToAccepted($qb, true);
		$this->leftJoinCacheActors($qb, 'actor_id');
		$this->leftJoinDetails($qb, 'id', 'ca');
		$qb->orderBy('f.creation', 'desc');

		if ($limit > 0) {
			$qb->setMaxResults($limit);
			$qb->setFirstResult($offset);
		}

		return $this->getFollowsFromRequest($qb);
	}

	/**
	 * Where a post has to be delivered for the followers of an actor to see
	 * it: one row per distinct inbox, resolved in the database.
	 *
	 * The fan-out only ever needed the inboxes, and there are as many of those
	 * as there are instances involved — not as many as there are followers.
	 *
	 * @return string[] the shared inbox where there is one, the personal inbox otherwise
	 */
	public function getFollowerInboxes(string $actorId): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$qb->selectDistinct('ca.shared_inbox')
			->addSelect('ca.inbox')
			->from(self::TABLE_FOLLOWS, 'f')
			->innerJoin('f', self::TABLE_CACHE_ACTORS, 'ca', $expr->eq('ca.id_prim', 'f.actor_id_prim'))
			->where($expr->eq('f.object_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($expr->eq('f.type', $qb->createNamedParameter(Follow::TYPE)))
			->andWhere($expr->eq('f.accepted', $qb->createNamedParameter('1')));

		$inboxes = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$inbox = (string)($data['shared_inbox'] ?? '');
			if ($inbox === '') {
				$inbox = (string)($data['inbox'] ?? '');
			}
			if ($inbox !== '' && !in_array($inbox, $inboxes, true)) {
				$inboxes[] = $inbox;
			}
		}
		$cursor->closeCursor();

		return $inboxes;
	}

	/**
	 * The follows towards this actor that still wait for approval.
	 *
	 * @return Follow[]
	 */
	public function getPendingByObjectId(string $actorId, int $limit = 0): array {
		$qb = $this->getFollowsSelectSql();
		$this->limitToPrim($qb, 'object_id_prim', $actorId);
		$this->limitToAccepted($qb, false);
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}
		$this->leftJoinCacheActors($qb, 'actor_id');
		$this->leftJoinDetails($qb, 'id', 'ca');
		$qb->orderBy('f.creation', 'desc');

		return $this->getFollowsFromRequest($qb);
	}

	/**
	 * @param string $actorId
	 *
	 * @return Follow[]
	 */
	public function getFollowingByActorId(string $actorId, int $limit = 0, int $offset = 0): array {
		$qb = $this->getFollowsSelectSql();
		$this->limitToPrim($qb, 'actor_id_prim', $actorId);
		$this->limitToAccepted($qb, true);
		if ($limit > 0) {
			$qb->setMaxResults($limit);
			$qb->setFirstResult($offset);
		}
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
	public function getFollowersByFollowId(string $followId, int $limit = 0): array {
		$qb = $this->getFollowsSelectSql();
		$this->limitToPrim($qb, 'follow_id_prim', $followId);
		$this->limitToAccepted($qb, true);
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}
		$this->leftJoinAccounts($qb, 'actor_id');

		return $this->getFollowsFromRequest($qb);
	}

	/**
	 * @param Follow $follow
	 */
	public function delete(Follow $follow) {
		$qb = $this->getFollowsDeleteSql();
		$this->limitToIdPrimString($qb, $follow->getId());

		$qb->executeStatement();
	}

	/**
	 * @param Follow $follow
	 */
	public function deleteByPersons(Follow $follow) {
		$qb = $this->getFollowsDeleteSql();
		$this->limitToPrim($qb, 'actor_id_prim', $follow->getActorId());
		$this->limitToPrim($qb, 'object_id_prim', $follow->getObjectId());

		$qb->executeStatement();
	}

	/**
	 * @param string $actorId
	 */
	/**
	 * The accounts of one instance that a follow row still names, in either
	 * direction — the ones following somebody here and the ones followed from
	 * here.
	 *
	 * Both halves matter to a purge: leaving the follows of a blocked instance
	 * behind keeps it in the delivery fan-out of every local post, and keeps
	 * its accounts in the follower counts and lists shown here.
	 *
	 * @return string[] actor ids
	 *
	 * @throws InvalidResourceException the domain is not one
	 */
	public function getActorIdsFromDomain(string $domain, int $limit = 100): array {
		$ids = [];
		foreach (['actor_id', 'object_id'] as $column) {
			$qb = $this->getQueryBuilder();
			$qb->selectDistinct('f.' . $column)
				->from(self::TABLE_FOLLOWS, 'f')
				->where(DomainBlocksRequestBuilder::onDomain($qb, 'f.' . $column, $domain))
				->setMaxResults($limit);

			$cursor = $qb->executeQuery();
			while ($data = $cursor->fetch()) {
				$ids[(string)$data[$column]] = true;
			}
			$cursor->closeCursor();
		}

		return array_slice(array_keys($ids), 0, $limit);
	}

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
		$this->limitToIdPrimString($qb, $id);

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

		$this->limitToPrim($qb, 'object_id_prim', $actorId);

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

		$this->limitToPrim($qb, 'actor_id_prim', $actorId);

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

		$qb->andWhere(
			$qb->expr()->orX(
				$qb->exprLimitInArray('actor_id_prim', $prims),
				$qb->exprLimitInArray('object_id_prim', $prims)
			)
		);

		return $this->getFollowsFromRequest($qb);
	}
}
