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


use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Cache\CacheActor;
use OCA\Social\Model\ActivityPub\Person;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\IDBConnection;

class CacheActorsRequest extends CacheActorsRequestBuilder {


	/**
	 * CacheActorsRequest constructor.
	 *
	 * @param IDBConnection $connection
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IDBConnection $connection, ConfigService $configService, MiscService $miscService
	) {
		parent::__construct($connection, $configService, $miscService);
	}


	/**
	 * insert cache about an Actor in database.
	 *
	 * @param Person $actor
	 * @param bool $local
	 *
	 * @return int
	 */
	public function save(Person $actor, bool $local = false): int {

		$qb = $this->getCacheActorsInsertSql();
		$qb->setValue('id', $qb->createNamedParameter($actor->getId()))
		   ->setValue('account', $qb->createNamedParameter($actor->getAccount()))
		   ->setValue('local', $qb->createNamedParameter(($local) ? '1' : '0'))
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
		   ->setValue('source', $qb->createNamedParameter($actor->getSource()));

		$qb->execute();

		return $qb->getLastInsertId();
	}


//	/**
//	 * get Cached value about an Actor, based on the account.
//	 *
//	 * @param string $account
//	 *
//	 * @return CacheActor
//	 * @throws CacheActorDoesNotExistException
//	 */
//	public function getFromAccount(string $account): CacheActor {
//		$qb = $this->getCacheActorsSelectSql();
//		$this->limitToAccount($qb, $account);
//
//		$cursor = $qb->execute();
//		$data = $cursor->fetch();
//		$cursor->closeCursor();
//
//		if ($data === false) {
//			throw new CacheActorDoesNotExistException();
//		}
//
//		return $this->parseCacheActorsSelectSql($data);
//	}


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
		$this->limitToIdString($qb, $id);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new CacheActorDoesNotExistException();
		}

		return $this->parseCacheActorsSelectSql($data);
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
		$this->limitToAccount($qb, $account);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new CacheActorDoesNotExistException();
		}

		return $this->parseCacheActorsSelectSql($data);
	}


	/**
	 * @param string $search
	 *
	 * @return Person[]
	 */
	public function searchAccounts(string $search): array {
		$qb = $this->getCacheActorsSelectSql();
		$this->searchInAccount($qb, $search);

		$accounts = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$accounts[] = $this->parseCacheActorsSelectSql($data);
		}
		$cursor->closeCursor();

		return $accounts;
	}


	/**
	 * delete cached version of an Actor, based on the UriId
	 *
	 * @param string $id
	 */
	public function deleteFromId(string $id) {
		$qb = $this->getCacheActorsDeleteSql();
		$this->limitToIdString($qb, $id);

		$qb->execute();
	}


}

