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

use DateTime;
use Exception;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

class CacheActorsRequest extends CacheActorsRequestBuilder {
	public const CACHE_TTL = 60 * 24 * 10; // 10d


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

		$qb->setValue('icon_id', $qb->createNamedParameter($iconId));
		$qb->generatePrimaryKey($actor->getId());

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
		}
	}


	/**
	 * Insert cache about an Actor in database.
	 */
	public function update(Person $actor): int {
		$qb = $this->getCacheActorsUpdateSql();
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

		$qb->set('icon_id', $qb->createNamedParameter($iconId));

		$this->limitToIdString($qb, $actor->getId());

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
		$qb->limitToIdString($id);
		$qb->leftJoinCacheDocuments('icon_id');

		return $this->getCacheActorFromRequest($qb);
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
		/** @var SocialQueryBuilder $qb */
		$qb->leftJoinCacheDocuments('icon_id');
		$this->leftJoinDetails($qb);
		$qb->limitResults(25);

		return $this->getCacheActorsFromRequest($qb);
	}


	/**
	 * @return Person[]
	 * @throws Exception
	 */
	public function getRemoteActorsToUpdate(): array {
		$qb = $this->getCacheActorsSelectSql();
		$this->limitToLocal($qb, false);
		$this->limitToCreation($qb, self::CACHE_TTL);

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

		$qb->execute();
	}


	/**
	 * @return array
	 */
	public function getSharedInboxes(): array {
		$qb = $this->getQueryBuilder();
		$qb->selectDistinct('shared_inbox')
		   ->from(self::TABLE_CACHE_ACTORS);
		$inbox = [];
		$cursor = $qb->execute();
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
}
