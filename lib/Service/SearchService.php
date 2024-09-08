<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Tools\Traits\TArrayTools;
use Psr\Log\LoggerInterface;

/**
 * Class SearchService
 *
 * @package OCA\Social\Service
 */
class SearchService {
	use TArrayTools;


	public const SEARCH_URI = 1;
	public const SEARCH_ACCOUNTS = 2;
	public const SEARCH_HASHTAGS = 4;
	public const SEARCH_CONTENT = 8;
	public const SEARCH_ALL = 15;

	private CacheActorService $cacheActorService;
	private HashtagService $hashtagService;
	private ConfigService $configService;
	private LoggerInterface $logger;


	/**
	 * ImportService constructor.
	 *
	 * @param CacheActorService $cacheActorService
	 * @param HashtagService $hashtagService
	 * @param ConfigService $configService
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		CacheActorService $cacheActorService,
		HashtagService $hashtagService,
		ConfigService $configService,
		LoggerInterface $logger
	) {
		$this->cacheActorService = $cacheActorService;
		$this->hashtagService = $hashtagService;
		$this->configService = $configService;
		$this->logger = $logger;
	}


	/**
	 * @param string $search
	 *
	 * @return Person[]
	 */
	public function searchUri(string $search): array {
		$type = $this->getTypeFromSearch($search);

		if ($search !== '' && $type & self::SEARCH_URI) {
			try {
				return [$this->cacheActorService->getFromId($search)];
			} catch (Exception $e) {
			}
		}

		return [];
	}


	/**
	 * @param string $search
	 *
	 * @return Person[]
	 */
	public function searchAccounts(string $search): array {
		$type = $this->getTypeFromSearch($search);

		if ($search === '' || !$type & self::SEARCH_ACCOUNTS) {
			return [];
		}

		$search = ltrim($search, '@');

		try {
			// search and cache eventual exact account first
			$this->cacheActorService->getFromAccount($search);
		} catch (Exception $e) {
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
				if (substr($search, 0, 4) === 'http') {
					return self::SEARCH_URI;
				}

				return self::SEARCH_ALL;
		}
	}
}
