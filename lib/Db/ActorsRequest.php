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
use OCA\Social\Model\ActivityPub\Person;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\IDBConnection;

class ActorsRequest extends ActorsRequestBuilder {


	/**
	 * ActorsRequest constructor.
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
	 * create a new Person in the database.
	 *
	 * @param Person $actor
	 *
	 * @return string
	 * @throws \Exception
	 */
	public function create(Person $actor): string {

		$id = $this->configService->getRoot() . '@' . $actor->getPreferredUsername();

		try {
			$qb = $this->getActorsInsertSql();

			$qb->setValue('id', $qb->createNamedParameter($id))
//			   ->setValue('type', $qb->createNamedParameter($actor->getType()))
			   ->setValue('user_id', $qb->createNamedParameter($actor->getUserId()))
			   ->setValue('name', $qb->createNamedParameter($actor->getName()))
			   ->setValue('summary', $qb->createNamedParameter($actor->getSummary()))
			   ->setValue(
				   'preferred_username', $qb->createNamedParameter($actor->getPreferredUsername())
			   )
			   ->setValue('public_key', $qb->createNamedParameter($actor->getPublicKey()))
			   ->setValue('private_key', $qb->createNamedParameter($actor->getPrivateKey()));

			$qb->execute();

			return $id;
		} catch (\Exception $e) {
			throw $e;
		}
	}


	/**
	 * return Actor from database based on the username
	 *
	 * @param string $username
	 *
	 * @return Person
	 * @throws ActorDoesNotExistException
	 */
	public function getFromUsername(string $username): Person {
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
	 * @param string $id
	 *
	 * @return Person
	 * @throws ActorDoesNotExistException
	 */
	public function getFromId(string $id): Person {
		$qb = $this->getActorsSelectSql();
		$this->limitToIdString($qb, $id);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ActorDoesNotExistException('Actor not found');
		}

		return $this->parseActorsSelectSql($data);
	}


	/**
	 * return Actor from database, based on the userId of the owner.
	 *
	 * @param string $userId
	 *
	 * @return Person
	 * @throws ActorDoesNotExistException
	 */
	public function getFromUserId(string $userId): Person {
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


	/**
	 * @param string $search
	 *
	 * @return Person[]
	 */
	public function searchFromUsername(string $search): array {
		$qb = $this->getActorsSelectSql();
		$this->searchInPreferredUsername($qb, $search);

		$accounts = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$accounts[] = $this->parseActorsSelectSql($data);
		}
		$cursor->closeCursor();

		return $accounts;
	}

}

