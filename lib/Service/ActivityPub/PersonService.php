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


namespace OCA\Social\Service\ActivityPub;


use daita\MySmallPhpTools\Traits\TArrayTools;
use Exception;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\RequestException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Person;
use OCA\Social\Service\ICoreService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\MiscService;


/**
 * Class PersonService
 *
 * @package OCA\Social\Service\ActivityPub
 */
class PersonService implements ICoreService {


	use TArrayTools;


	/** @var CacheActorsRequest */
	private $cacheActorsRequest;

	/** @var InstanceService */
	private $instanceService;

	/** @var MiscService */
	private $miscService;


	/**
	 * UndoService constructor.
	 *
	 * @param CacheActorsRequest $cacheActorsRequest
	 * @param InstanceService $instanceService
	 * @param MiscService $miscService
	 */
	public function __construct(
		CacheActorsRequest $cacheActorsRequest, InstanceService $instanceService,
		MiscService $miscService
	) {
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->instanceService = $instanceService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $id
	 *
	 * @return Person
	 * @throws RequestException
	 * @throws InvalidResourceException
	 */
	public function getFromId(string $id): Person {

		$posAnchor = strpos($id, '#');
		if ($posAnchor !== false) {
			$id = substr($id, 0, $posAnchor);
		}

		try {
			$actor = $this->cacheActorsRequest->getFromId($id);
		} catch (CacheActorDoesNotExistException $e) {
			$object = $this->instanceService->retrieveObject($id);
			$actor = new Person();
			$actor->import($object);

			if ($actor->getType() !== 'Person') {
				throw new InvalidResourceException();
			}

			$actor->setPreferredUsername($this->get('preferredUsername', $object, ''));
			$actor->setPublicKey($this->get('publicKey.publicKeyPem', $object));
			$actor->setSharedInbox($this->get('endpoints.sharedInbox', $object));
			$actor->setAccount($actor->getPreferredUsername() . '@' . $this->get('_host', $object));
			try {
				$this->save($actor);
			} catch (Exception $e) {
				throw new InvalidResourceException();
			}
		}

		return $actor;
	}


	/**
	 * @param string $account
	 *
	 * @return Person
	 * @throws RequestException
	 * @throws Exception
	 */
	public function getFromAccount(string $account): Person {

		try {
			$actor = $this->cacheActorsRequest->getFromAccount($account);
		} catch (CacheActorDoesNotExistException $e) {
			$object = $this->instanceService->retrieveAccount($account);
			$actor = new Person();
			$actor->import($object);

			if ($actor->getType() !== 'Person') {
				throw new InvalidResourceException();
			}

			$actor->setAccount($account);
			$actor->setPreferredUsername($this->get('preferredUsername', $object, ''));
			$actor->setPublicKey($this->get('publicKey.publicKeyPem', $object));
			$actor->setSharedInbox($this->get('endpoints.sharedInbox', $object));
			$this->save($actor);
		}

		return $actor;
	}


	/**
	 * @param string $search
	 *
	 * @return Person[]
	 */
	public function searchCachedAccounts(string $search): array {
		return $this->cacheActorsRequest->searchAccounts($search);
	}


	/**
	 * This method is called when saving the Follow object
	 *
	 * @param ACore $person
	 *
	 * @throws Exception
	 */
	public function save(ACore $person) {
		/** @var Person $person */
		$this->cacheActorsRequest->save($person);
	}


}

