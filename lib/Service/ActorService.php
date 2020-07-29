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


use daita\MySmallPhpTools\Traits\TArrayTools;
use OCA\Social\AP;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Model\ActivityPub\Actor\Person;


/**
 * Class ActorService
 *
 * @package OCA\Social\Service
 */
class ActorService {


	use TArrayTools;


	/** @var CacheActorsRequest */
	private $cacheActorsRequest;

	/** @var CacheDocumentsRequest */
	private $cacheDocumentsRequest;

	/** @var CurlService */
	private $curlService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * ActorService constructor.
	 *
	 * @param CacheActorsRequest $cacheActorsRequest
	 * @param CacheDocumentsRequest $cacheDocumentsRequest
	 * @param CurlService $curlService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		CacheActorsRequest $cacheActorsRequest, CacheDocumentsRequest $cacheDocumentsRequest,
		CurlService $curlService,
		ConfigService $configService,
		MiscService $miscService
	) {
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->cacheDocumentsRequest = $cacheDocumentsRequest;
		$this->curlService = $curlService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param Person $actor
	 *
	 * @throws ItemAlreadyExistsException
	 */
	public function cacheLocalActor(Person $actor) {
		$actor->setLocal(true);
		$actor->setSource(json_encode($actor, JSON_UNESCAPED_SLASHES));

		try {
			$this->cacheActorsRequest->getFromId($actor->getId());
			$this->update($actor);
		} catch (CacheActorDoesNotExistException $e) {
			$this->save($actor);
		}
	}


	/**
	 * @param Person $actor
	 *
	 * @throws ItemAlreadyExistsException
	 */
	public function save(Person $actor) {
		$this->cacheDocumentIfNeeded($actor);
		$this->cacheActorsRequest->save($actor);
	}


	/**
	 * @param Person $actor
	 *
	 * @return int
	 * @throws ItemAlreadyExistsException
	 */
	public function update(Person $actor): int {
		$this->cacheDocumentIfNeeded($actor);

		return $this->cacheActorsRequest->update($actor);
	}


	/**
	 * @param Person $actor
	 *
	 * @throws ItemAlreadyExistsException
	 */
	private function cacheDocumentIfNeeded(Person $actor) {
		if ($actor->hasIcon()) {
			$icon = $actor->getIcon();
			try {
				$cache = $this->cacheDocumentsRequest->getByUrl($icon->getUrl());
				$actor->setIcon($cache);
			} catch (CacheDocumentDoesNotExistException $e) {
				try {
					$interface = AP::$activityPub->getInterfaceFromType($icon->getType());
					$interface->save($icon);
				} catch (ItemUnknownException $e) {
				}
			}
		}
	}

}

