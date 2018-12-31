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
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
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


	/** @var string */
	private $viewerId = '';


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
	 * @param string $viewerId
	 */
	public function setViewerId(string $viewerId) {
		$this->viewerId = $viewerId;
		$this->cacheActorsRequest->setViewerId($viewerId);
	}

	public function getViewerId(): string {
		return $this->viewerId;
	}


	/**
	 * @param Person $actor
	 * @param bool $refresh
	 */
	public function cacheLocalActor(Person $actor, bool $refresh = false) {
		if ($refresh) {
			$this->cacheActorsRequest->deleteFromId($actor->getId());
		}

		$actor->setLocal(true);
		$actor->setSource(json_encode($actor, JSON_UNESCAPED_SLASHES));

		$this->save($actor);
	}


	/**
	 * @param Person $actor
	 */
	public function save(Person $actor) {
		$this->cacheDocumentIfNeeded($actor);
		$this->cacheActorsRequest->save($actor);
	}


	/**
	 * @param Person $actor
	 */
	public function update(Person $actor) {
		$this->cacheDocumentIfNeeded($actor);
		$this->cacheActorsRequest->update($actor);
	}


	/**
	 * @param Person $actor
	 */
	private function cacheDocumentIfNeeded(Person $actor) {
		if ($actor->gotIcon()) {
			try {
				$icon = $this->cacheDocumentsRequest->getBySource(
					$actor->getIcon()
						  ->getUrl()
				);
				$actor->setIcon($icon);
			} catch (CacheDocumentDoesNotExistException $e) {
				$this->cacheDocumentsRequest->save($actor->getIcon());
			}
		}
	}

}

