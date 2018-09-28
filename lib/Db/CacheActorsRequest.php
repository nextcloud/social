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


use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor;
use OCA\Social\Model\ActivityPub\Cache\CacheActor;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\IDBConnection;

class CacheActorsRequest extends CacheActorsRequestBuilder {


	/**
	 * ServicesRequest constructor.
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
	 * @param Actor $actor
	 *
	 * @return int
	 * @throws \Exception
	 */
	public function create(Actor $actor, array $object): int {

		try {
			$qb = $this->getCacheActorsInsertSql();
			$qb->setValue('account', $qb->createNamedParameter($actor->getAccount()))
			   ->setValue('url', $qb->createNamedParameter($actor->getId()))
			   ->setValue('actor', $qb->createNamedParameter(json_encode($object)));

			$qb->execute();

			return $qb->getLastInsertId();
		} catch (\Exception $e) {
			throw $e;
		}
	}


	/**
	 * return service.
	 *
	 * @param string $account
	 *
	 * @return CacheActor
	 * @throws CacheActorDoesNotExistException
	 */
	public function getFromAccount(string $account): Actor {
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
	 * return service.
	 *
	 * @param string $url
	 *
	 * @return CacheActor
	 * @throws CacheActorDoesNotExistException
	 */
	public function getFromUrl(string $url): CacheActor {
		$qb = $this->getCacheActorsSelectSql();
		$this->limitToUrl($qb, $url);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new CacheActorDoesNotExistException();
		}

		return $this->parseCacheActorsSelectSql($data);
	}


}

