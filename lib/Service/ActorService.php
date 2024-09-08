<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AP;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class ActorService
 *
 * @package OCA\Social\Service
 */
class ActorService {
	use TArrayTools;


	private CacheActorsRequest $cacheActorsRequest;

	private CacheDocumentsRequest $cacheDocumentsRequest;

	private CurlService $curlService;

	private ConfigService $configService;

	private MiscService $miscService;


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
	public function cacheLocalActorDetails(Person $actor) {
		$this->cacheActorsRequest->updateDetails($actor);
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
