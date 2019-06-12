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

namespace OCA\Social\Service;


use daita\MySmallPhpTools\Exceptions\MalformedArrayException;
use daita\MySmallPhpTools\Traits\TArrayTools;
use Exception;
use OCA\Social\AP;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\RequestContentException;
use OCA\Social\Exceptions\RequestResultNotJsonException;
use OCA\Social\Exceptions\RetrieveAccountFormatException;
use OCA\Social\Exceptions\RequestNetworkException;
use OCA\Social\Exceptions\RequestResultSizeException;
use OCA\Social\Exceptions\RequestServerException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\Actor\Person;


class CacheActorService {


	use TArrayTools;


	/** @var CacheActorsRequest */
	private $cacheActorsRequest;

	/** @var CurlService */
	private $curlService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/** @var Person */
	private $viewer = null;


	/**
	 * CacheService constructor.
	 *
	 * @param CacheActorsRequest $cacheActorsRequest
	 * @param CurlService $curlService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		CacheActorsRequest $cacheActorsRequest, CurlService $curlService,
		ConfigService $configService, MiscService $miscService
	) {
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->curlService = $curlService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param Person $viewer
	 */
	public function setViewer(Person $viewer) {
		$this->viewer = $viewer;
		$this->cacheActorsRequest->setViewer($viewer);
	}


	/**
	 * @param string $id
	 *
	 * @param bool $refresh
	 *
	 * @return Person
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws RequestResultNotJsonException
	 * @throws UnauthorizedFediverseException
	 */
	public function getFromId(string $id, bool $refresh = false): Person {

		$posAnchor = strpos($id, '#');
		if ($posAnchor !== false) {
			$id = substr($id, 0, $posAnchor);
		}

		try {
			if ($refresh) {
				throw new CacheActorDoesNotExistException();
			}

			$actor = $this->cacheActorsRequest->getFromId($id);
		} catch (CacheActorDoesNotExistException $e) {
			$object = $this->curlService->retrieveObject($id);

			/** @var Person $actor */
			$actor = AP::$activityPub->getItemFromData($object);
			if ($actor->getType() !== Person::TYPE) {
				throw new InvalidResourceException();
			}

			if ($id !== $actor->getId()) {
				throw new InvalidOriginException(
					'CacheActorService::getFromId - id: ' . $id . ' - actorId: ' . $actor->getId()
				);
			}

			$actor->setAccount($actor->getPreferredUsername() . '@' . $this->get('_host', $object));
			try {
				$this->save($actor);
			} catch (Exception $e) {
				throw new InvalidResourceException($e->getMessage());
			}
		}

		return $actor;
	}


	/**
	 * @param string $account
	 *
	 * @return Person
	 * @throws CacheActorDoesNotExistException
	 */
	public function getFromLocalAccount(string $account): Person {
		if (strrpos($account, '@')) {
			$account = substr($account, 0, strrpos($account, '@'));
		}

		return $this->cacheActorsRequest->getFromLocalAccount($account);
	}


	/**
	 * @param string $account
	 *
	 * @param bool $retrieve
	 *
	 * @return Person
	 * @throws CacheActorDoesNotExistException
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RetrieveAccountFormatException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws RequestResultNotJsonException
	 */
	public function getFromAccount(string $account, bool $retrieve = true): Person {

		try {
			$actor = $this->cacheActorsRequest->getFromAccount($account);
		} catch (CacheActorDoesNotExistException $e) {
			if (!$retrieve) {
				throw new CacheActorDoesNotExistException();
			}

			$actor = $this->curlService->retrieveAccount($account);
			$actor->setAccount($account);
			try {
				$this->save($actor);
			} catch (Exception $e) {
				throw new InvalidResourceException($e->getMessage());
			}
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
	 * @return int
	 * @throws Exception
	 */
	public function missingCacheRemoteActors(): int {
		// TODO - looking for missing cache remote actors...
		$missing = [];

		foreach ($missing as $item) {
			try {
				$this->getFromId($item->getId());
			} catch (Exception $e) {
			}
		}

		return sizeof($missing);
	}


	/**
	 * @return int
	 * @throws Exception
	 */
	public function manageCacheRemoteActors(): int {
		$update = $this->cacheActorsRequest->getRemoteActorsToUpdate();

		foreach ($update as $item) {
			try {
				$this->getFromId($item->getId(), true);
			} catch (Exception $e) {
			}
		}

		return sizeof($update);
	}


	/**
	 * @param Person $actor
	 */
	private function save(Person $actor) {
		try {
			$interface = AP::$activityPub->getInterfaceFromType(Person::TYPE);
			$interface->save($actor);
		} catch (ItemUnknownException $e) {
		}
	}
}
