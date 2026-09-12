<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateInterval;
use DateTime;
use Exception;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

class CacheActorsRequest extends CacheActorsRequestBuilder {
	public const CACHE_TTL = 60 * 24 * 10; // 10d
	/** Remote actors synced per cron pass. */
	public const SYNC_BATCH = 50;
	public const DETAILS_TTL = 60 * 18; // 18h

	/**
	 * Insert cache about an Actor in database.
	 */
	public function save(Person $actor): void {
		$qb = $this->getCacheActorsInsertSql();
		$qb->setValue('id', $qb->createNamedParameter($actor->getId()))
			->setValue('id_prim', $qb->createNamedParameter($qb->prim($actor->getId())))
			->setValue('account', $qb->createNamedParameter($actor->getAccount()))
			->setValue('type', $qb->createNamedParameter($actor->getType()))
			->setValue('local', $qb->createNamedParameter(($actor->isLocal()) ? '1' : '0'))
			->setValue('following', $qb->createNamedParameter($actor->getFollowing()))
			->setValue('followers', $qb->createNamedParameter($actor->getFollowers()))
			->setValue('inbox', $qb->createNamedParameter($actor->getInbox()))
			->setValue('shared_inbox', $qb->createNamedParameter($actor->getSharedInbox()))
			->setValue('outbox', $qb->createNamedParameter($actor->getOutbox()))
			->setValue('featured', $qb->createNamedParameter($actor->getFeatured()))
			->setValue('url', $qb->createNamedParameter($actor->getUrl()))
			->setValue(
				'preferred_username', $qb->createNamedParameter($actor->getPreferredUsername())
			)
			->setValue('name', $qb->createNamedParameter($actor->getName()))
			->setValue('summary', $qb->createNamedParameter($actor->getSummary()))
			->setValue('public_key', $qb->createNamedParameter($actor->getPublicKey()))
			->setValue('source', $qb->createNamedParameter($actor->getSource()))
			->setValue('details', $qb->createNamedParameter(json_encode($actor->getDetailsAll())));

		try {
			if ($actor->getCreation() > 0) {
				$dTime = new DateTime();
				$dTime->setTimestamp($actor->getCreation());
			} else {
				$dTime = new DateTime('now');
			}

			$qb->setValue(
				'creation',
				$qb->createNamedParameter($dTime, IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		if ($actor->hasIcon()) {
			$iconId = $actor->getIcon()
				->getId();
		} else {
			$iconId = $actor->getIconId();
		}

		$qb->setValue('icon_id', $qb->createNamedParameter($qb->prim($iconId)));
		$qb->generatePrimaryKey($actor->getId());

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
		}
	}

	public function update(Person $actor): int {
		$qb = $this->getCacheActorsUpdateSql();

		// The handle is derived, not carried on the wire: a local actor gets it
		// from the configured address, and a remote one from the host it was
		// fetched over. A caller that has one is correcting it — refreshing a
		// local actor after the address changed is the case that matters — and
		// one that has none must not be able to erase what is stored.
		if ($actor->getAccount() !== '') {
			$qb->set('account', $qb->createNamedParameter($actor->getAccount()));
		}

		$qb->set('following', $qb->createNamedParameter($actor->getFollowing()))
			->set('followers', $qb->createNamedParameter($actor->getFollowers()))
			->set('inbox', $qb->createNamedParameter($actor->getInbox()))
			->set('shared_inbox', $qb->createNamedParameter($actor->getSharedInbox()))
			->set('outbox', $qb->createNamedParameter($actor->getOutbox()))
			->set('featured', $qb->createNamedParameter($actor->getFeatured()))
			->set('url', $qb->createNamedParameter($actor->getUrl()))
			->set(
				'preferred_username', $qb->createNamedParameter($actor->getPreferredUsername())
			)
			->set('name', $qb->createNamedParameter($actor->getName()))
			->set('summary', $qb->createNamedParameter($actor->getSummary()))
			->set('public_key', $qb->createNamedParameter($actor->getPublicKey()))
			->set('source', $qb->createNamedParameter($actor->getSource()))
			->set('details', $qb->createNamedParameter(json_encode($actor->getDetailsAll())));

		try {
			if ($actor->getCreation() > 0) {
				$dTime = new DateTime();
				$dTime->setTimestamp($actor->getCreation());
			} else {
				$dTime = new DateTime('now');
			}
			$qb->set(
				'creation',
				$qb->createNamedParameter($dTime, IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		if ($actor->hasIcon()) {
			$iconId = $actor->getIcon()
				->getId();
		} else {
			$iconId = $actor->getIconId();
		}

		$qb->set('icon_id', $qb->createNamedParameter($qb->prim($iconId)));
		$this->limitToIdPrimString($qb, $actor->getId());

		return $qb->executeStatement();
	}

	public function updateDetails(Person $actor): int {
		$qb = $this->getCacheActorsUpdateSql();
		$qb->set('details', $qb->createNamedParameter(json_encode($actor->getDetailsAll())));

		try {
			$qb->set(
				'details_update',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		$this->limitToIdPrimString($qb, $actor->getId());

		return $qb->executeStatement();
	}

	/**
	 * get Cached version of an Actor, based on the UriId
	 *
	 * @param string $id
	 *
	 * @return Person
	 * @throws CacheActorDoesNotExistException
	 */
	public function getFromId(string $id): Person {
		$qb = $this->getCacheActorsSelectSql();
		$this->limitToIdPrimString($qb, $id);
		$qb->leftJoinCacheDocuments('icon_id');

		return $this->getCacheActorFromRequest($qb);
	}

	/**
	 * The cached actors behind a set of ids, in one query.
	 *
	 * For the callers that hold a list of actor ids and want whatever is known
	 * about them — a page of reports, a page of blocks — instead of a lookup,
	 * and a federated fetch on every miss, per row.
	 *
	 * @param string[] $ids
	 *
	 * @return Person[] keyed by actor id; ids that are not cached are absent
	 */
	public function getFromIds(array $ids): array {
		$qb = $this->getCacheActorsSelectSql();

		$prims = [];
		foreach ($ids as $id) {
			$prim = $qb->prim($id);
			if ($prim !== '') {
				$prims[$prim] = $prim;
			}
		}

		if ($prims === []) {
			return [];
		}

		$qb->limitInArray('id_prim', array_values($prims));
		$qb->leftJoinCacheDocuments('icon_id');

		$actors = [];
		foreach ($this->getCacheActorsFromRequest($qb) as $actor) {
			$actors[$actor->getId()] = $actor;
		}

		return $actors;
	}

	/**
	 * get Cached version of an Actor, based on the Account
	 *
	 * @param string $account
	 *
	 * @return Person
	 * @throws CacheActorDoesNotExistException
	 */
	public function getFromAccount(string $account): Person {
		$qb = $this->getCacheActorsSelectSql();
		$qb->limitToAccount($account);
		$qb->leftJoinCacheDocuments('icon_id');
		$this->leftJoinDetails($qb);

		return $this->getCacheActorFromRequest($qb);
	}

	/**
	 * get Cached version of a local Actor, based on the preferred username
	 *
	 * @param string $account
	 *
	 * @return Person
	 * @throws CacheActorDoesNotExistException
	 */
	public function getFromLocalAccount(string $account): Person {
		$qb = $this->getCacheActorsSelectSql();
		$qb->limitToPreferredUsername($account);
		$qb->limitToLocal(true);
		$qb->leftJoinCacheDocuments('icon_id');
		$this->leftJoinDetails($qb);

		return $this->getCacheActorFromRequest($qb);
	}

	/**
	 * @param string $search
	 *
	 * @return Person[]
	 */
	public function searchAccounts(string $search): array {
		$qb = $this->getCacheActorsSelectSql();
		$qb->searchInAccount($search);
		$qb->leftJoinCacheDocuments('icon_id');
		$this->leftJoinDetails($qb);
		$qb->limitResults(25);

		return $this->getCacheActorsFromRequest($qb);
	}

	/**
	 * @return Person[]
	 * @throws Exception
	 */
	public function getRemoteActorsToUpdate(bool $force = false): array {
		$qb = $this->getCacheActorsSelectSql();
		$qb->limitToLocal(false);
		if (!$force) {
			$qb->limitToCreation(self::CACHE_TTL);
			// One cron pass syncs a bounded batch; on a large instance the full set
			// would be thousands of outbound requests in a single job.
			$qb->setMaxResults(self::SYNC_BATCH);
		}

		return $this->getCacheActorsFromRequest($qb);
	}

	/**
	 * @return Person[]
	 * @throws Exception
	 */
	public function getRemoteActorsToUpdateDetails(bool $force = false): array {
		$qb = $this->getCacheActorsSelectSql();
		$qb->limitToLocal(false);
		if (!$force) {
			$date = new DateTime('now');
			$date->sub(new DateInterval('PT' . self::DETAILS_TTL . 'M'));
			$qb->limitToDBFieldDateTime('details_update', $date, true);
			// three outbound requests per actor (followers, following, outbox),
			// so one cron pass takes a bounded batch just as the sibling above
			// does — the rest are picked up by the next pass
			$qb->setMaxResults(self::SYNC_BATCH);
		}

		$qb->orderBy('ca.details_update', 'asc');

		return $this->getCacheActorsFromRequest($qb);
	}

	/**
	 * delete cached version of an Actor, based on the UriId
	 *
	 * @param string $id
	 */
	public function deleteCacheById(string $id) {
		$qb = $this->getCacheActorsDeleteSql();
		$qb->limitToIdPrim($qb->prim($id));

		$qb->executeStatement();
	}

	/**
	 * The cached actors of one instance, a page at a time.
	 *
	 * Bounded because a domain purge must not read a whole instance's accounts
	 * into memory to delete them; the caller deletes each row it is handed, so
	 * the next call returns the next page without an offset to keep track of.
	 *
	 * @return string[] actor ids
	 *
	 * @throws InvalidResourceException the domain is not one
	 */
	public function getIdsFromDomain(string $domain, int $limit = 100): array {
		$qb = $this->getQueryBuilder();
		$qb->select('ca.id')
			->from(self::TABLE_CACHE_ACTORS, 'ca')
			->where(DomainBlocksRequestBuilder::onDomain($qb, 'ca.id', $domain))
			->setMaxResults($limit);

		$ids = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$ids[] = (string)$data['id'];
		}
		$cursor->closeCursor();

		return $ids;
	}

	/**
	 * @return array
	 */
	public function getSharedInboxes(): array {
		$qb = $this->getQueryBuilder();
		$qb->selectDistinct('shared_inbox')
			->from(self::TABLE_CACHE_ACTORS)
			// an actor with no shared inbox used to come back as '', which the
			// caller then turned into a delivery to the host ''
			->where($qb->expr()->neq('shared_inbox', $qb->createNamedParameter('')))
			->andWhere($qb->expr()->eq('local', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$inbox = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$inbox[] = $data['shared_inbox'];
		}
		$cursor->closeCursor();

		return $inbox;
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Person[]
	 */
	public function probeActors(ProbeOptions $options): array {
		switch (strtolower($options->getProbe())) {
			case ProbeOptions::FOLLOWING:
				$result = $this->probeActorsFollowing($options);
				break;
			case ProbeOptions::FOLLOWERS:
				$result = $this->probeActorsFollowers($options);
				break;
			default:
				return [];
		}

		if ($options->isInverted()) {
			// in case we inverted the order during the request, we revert the results
			$result = array_reverse($result);
		}

		return $result;
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Person[]
	 */
	public function probeActorsFollowing(ProbeOptions $options): array {
		$qb = $this->getCacheActorsSelectSql($options->getFormat());

		$qb->paginate($options);

		$qb->leftJoin(
			$qb->getDefaultSelectAlias(),
			CoreRequestBuilder::TABLE_FOLLOWS,
			'ca_f',
			// object_id of follow is equal to actor's id
			$qb->expr()->eq('ca.id_prim', 'ca_f.object_id_prim')
		);

		// follow must be accepted
		$qb->limitToType(Follow::TYPE, 'ca_f');
		$qb->limitToAccepted(true, 'ca_f');
		// actor_id of follow is equal to requested account
		$qb->limitToActorIdPrim($qb->prim($options->getAccountId()), 'ca_f');

		return $this->getCacheActorsFromRequest($qb);
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Person[]
	 */
	public function probeActorsFollowers(ProbeOptions $options): array {
		$qb = $this->getCacheActorsSelectSql($options->getFormat());

		$qb->paginate($options);

		$qb->leftJoin(
			$qb->getDefaultSelectAlias(),
			CoreRequestBuilder::TABLE_FOLLOWS,
			'ca_f',
			// actor_id of follow is equal to actor's id
			$qb->expr()->eq('ca.id_prim', 'ca_f.actor_id_prim')
		);

		// follow must be accepted
		$qb->limitToType(Follow::TYPE, 'ca_f');
		$qb->limitToAccepted(true, 'ca_f');
		// object_id of follow is equal to requested account
		$qb->limitToObjectIdPrim($qb->prim($options->getAccountId()), 'ca_f');

		return $this->getCacheActorsFromRequest($qb);
	}

	/**
	 * As of today, returned format is not important. Remove this line if this method
	 * is used somewhere else with the need of a specific format
	 *
	 * @param array $ids
	 *
	 * @return array
	 */
	public function getFromNids(array $ids): array {
		$qb = $this->getCacheActorsSelectSql();

		$qb->limitInArray('nid', $ids);

		return $this->getCacheActorsFromRequest($qb);
	}
}
