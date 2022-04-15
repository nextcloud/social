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

use daita\MySmallPhpTools\Traits\Nextcloud\nc20\TNC20Logger;
use daita\MySmallPhpTools\Traits\TArrayTools;
use Exception;
use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * Class SearchService
 *
 * @package OCA\Social\Service
 */
class SearchService {
	use TArrayTools;
	use TNC20Logger;


	public const SEARCH_ACCOUNTS = 1;
	public const SEARCH_HASHTAGS = 2;
	public const SEARCH_CONTENT = 4;
	public const SEARCH_ALL = 7;


	private CacheActorService $cacheActorService;

	private HashtagService $hashtagService;

	private ConfigService $configService;

	private MiscService $miscService;


	/**
	 * ImportService constructor.
	 *
	 * @param CacheActorService $cacheActorService
	 * @param HashtagService $hashtagService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		CacheActorService $cacheActorService, HashtagService $hashtagService,
		ConfigService $configService, MiscService $miscService
	) {
		$this->cacheActorService = $cacheActorService;
		$this->hashtagService = $hashtagService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $search
	 *
	 * @return Person[]
	 */
	public function searchAccounts(string $search): array {
		$result = [];
		$type = $this->getTypeFromSearch($search);

		if ($search === '' || !$type & self::SEARCH_ACCOUNTS) {
			return $result;
		}

		if (substr($search, 0, 1) === '@') {
			$search = substr($search, 1);
		}

		try {
			$this->cacheActorService->getFromAccount($search);
		} catch (Exception $e) {
			$this->exception($e, self::$NOTICE, ['search' => $search]);
		}

		return $this->cacheActorService->searchCachedAccounts($search);
	}


	/**
	 * @param string $search
	 *
	 * @return array
	 */
	public function searchHashtags(string $search): array {
		$result = [];
		$type = $this->getTypeFromSearch($search);
		if ($search === '' || !$type & self::SEARCH_HASHTAGS) {
			return $result;
		}

		if (substr($search, 0, 1) === '#') {
			$search = substr($search, 1);
		}

		return $this->hashtagService->searchHashtags($search, true);
	}


	/**
	 * @param string $search
	 *
	 * @return array
	 */
	public function searchStreamContent(string $search): array {
		$result = [];

		$type = $this->getTypeFromSearch($search);
		if ($search === '' || !$type & self::SEARCH_CONTENT) {
			return $result;
		}

		// TODO : search using FullTextSearch ?
		return $result;
	}


	/**
	 * @param string $search
	 *
	 * @return int
	 */
	private function getTypeFromSearch(string $search): int {
		$char = substr($search, 0, 1);
		switch ($char) {
			case '@':
				return self::SEARCH_ACCOUNTS;

			case '#':
				return self::SEARCH_HASHTAGS;

			default:
				return self::SEARCH_ALL;
		}
	}
}
