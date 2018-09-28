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
use OCA\Social\Model\ActivityPub\Actor;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\IDBConnection;

class ActorsRequest extends ActorsRequestBuilder {


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
	public function create(Actor $actor): int {

		try {
			$qb = $this->getActorsInsertSql();
			$qb->setValue('type', $qb->createNamedParameter($actor->getType()))
			   ->setValue('user_id', $qb->createNamedParameter($actor->getUserId()))
			   ->setValue(
				   'preferred_username', $qb->createNamedParameter($actor->getPreferredUsername())
			   )
			   ->setValue('public_key', $qb->createNamedParameter($actor->getPublicKey()))
			   ->setValue('private_key', $qb->createNamedParameter($actor->getPrivateKey()));

			$qb->execute();

			return $qb->getLastInsertId();
		} catch (\Exception $e) {
			throw $e;
		}
	}


	/**
	 * return service.
	 *
	 * @param string $username
	 *
	 * @return Actor
	 * @throws ActorDoesNotExistException
	 */
	public function getFromUsername(string $username): Actor {
		$qb = $this->getActorsSelectSql();
		$this->limitToPreferredUsername($qb, $username);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ActorDoesNotExistException('Actor not found');
		}

		return $this->parseActorsSelectSql($data);
	}



	/**
	 * return service.
	 *
	 * @param string $userId
	 *
	 * @return Actor
	 * @throws ActorDoesNotExistException
	 */
	public function getFromUserId(string $userId): Actor {
		$qb = $this->getActorsSelectSql();
		$this->limitToUserId($qb, $userId);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ActorDoesNotExistException('Actor not found');
		}

		return $this->parseActorsSelectSql($data);
	}




//
//	/**
//	 * @param Service $service
//	 *
//	 * @return bool
//	 */
//	public function update(Service $service): bool {
//
//		try {
//			$this->getService($service->getId());
//		} catch (ServiceDoesNotExistException $e) {
//			return false;
//		}
//
//		$qb = $this->getServicesUpdateSql();
//		$qb->set('address', $qb->createNamedParameter($service->getAddress()));
//		$qb->set('config', $qb->createNamedParameter(json_encode($service->getConfigAll())));
//		$qb->set('status', $qb->createNamedParameter($service->getStatus()));
//		$qb->set('config', $qb->createNamedParameter(json_encode($service->getConfigAll())));
//
//		$this->limitToId($qb, $service->getId());
//
//		$qb->execute();
//
//		return true;
//	}
//

//	/**
//	 * @param int $serviceId
//	 */
//	public function delete(int $serviceId) {
//		$qb = $this->getServicesDeleteSql();
//		$this->limitToId($qb, $serviceId);
//
//		$qb->execute();
//	}


}
