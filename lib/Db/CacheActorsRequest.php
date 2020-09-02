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
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Exception;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\DB\QueryBuilder\IQueryBuilder;

class CacheActorsRequest extends CacheActorsRequestBuilder {


	const CACHE_TTL = 60 * 24; // 1d


	/**
	 * insert cache about an Actor in database.
	 *
	 * @param Person $actor
	 */
	public function save(Person $actor) {
		$qb = $this->getCacheActorsInsertSql();
		$qb->setValue('id', $qb->createNamedParameter($actor->getId()))
		   ->setValue('id_prim', $qb->createNamedParameter($this->prim($actor->getId())))
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
			$qb->execute();
		} catch (UniqueConstraintViolationException $e) {
		}
	}


	/**
	 * insert cache about an Actor in database.
	 *
	 * @param Person $actor
	 *
	 * @return int
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

		return $qb->execute();
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
		$this->limitToPreferredUsername($qb, $account);
		$this->limitToLocal($qb, true);
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
		$this->searchInAccount($qb, $search);
		$qb->leftJoinCacheDocuments('icon_id');
		$this->leftJoinDetails($qb);
		$this->limitResults($qb, 25);

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
		$this->limitToIdString($qb, $id);

		$qb->execute();
	}

}

