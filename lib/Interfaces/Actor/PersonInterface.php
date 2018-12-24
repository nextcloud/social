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


namespace OCA\Social\Interfaces\Actor;


use daita\MySmallPhpTools\Traits\TArrayTools;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;


/**
 * Class PersonService
 *
 * @package OCA\Social\Service\ActivityPub
 */
class PersonInterface implements IActivityPubInterface {


	use TArrayTools;


	/** @var CacheActorsRequest */
	private $cacheActorsRequest;

	/** @var ActorService */
	private $actorService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * UndoService constructor.
	 *
	 * @param CacheActorsRequest $cacheActorsRequest
	 * @param ActorService $actorService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		CacheActorsRequest $cacheActorsRequest, ActorService $actorService,
		ConfigService $configService, MiscService $miscService
	) {
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->actorService = $actorService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param ACore $person
	 */
	public function processIncomingRequest(ACore $person) {
	}


	/**
	 * @param ACore $item
	 */
	public function processResult(ACore $item) {
	}


	/**
	 * @param string $id
	 *
	 * @return ACore
	 * @throws ItemNotFoundException
	 */
	public function getItemById(string $id): ACore {
		try {
			$actor = $this->cacheActorsRequest->getFromId($id);

			return $actor;
		} catch (CacheActorDoesNotExistException $e) {
			throw new ItemNotFoundException();
		}
	}


	/**
	 * @param ACore $person
	 */
	public function save(ACore $person) {
		/** @var Person $person */
		try {
			$this->getItemById($person->getId());
			$this->actorService->update($person);
		} catch (ItemNotFoundException $e) {
			$this->actorService->save($person);
		}
	}


	/**
	 * @param ACore $activity
	 * @param ACore $item
	 *
	 * @throws InvalidOriginException
	 */
	public function activity(Acore $activity, ACore $item) {
		/** @var Person $item */

		if ($activity->getType() === Update::TYPE) {
			$activity->checkOrigin($item->getId());
			$item->setCreation($activity->getOriginCreationTime());

			try {
				$current = $this->cacheActorsRequest->getFromId($item->getId());
				if ($current->getCreation() < $activity->getOriginCreationTime()) {
					$this->cacheActorsRequest->update($item);
				}
			} catch (CacheActorDoesNotExistException $e) {
				$this->cacheActorsRequest->save($item);
			}

		}
	}


	/**
	 * @param ACore $item
	 *
	 * @throws InvalidOriginException
	 */
	public function delete(ACore $item) {
		$item->checkOrigin(($item->getId()));

		/** @var Person $item */
		$this->cacheActorsRequest->deleteFromId($item->getId());
	}


}

