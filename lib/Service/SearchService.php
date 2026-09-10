<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Db\StreamRequest;
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
	private StreamRequest $streamRequest;
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
		StreamRequest $streamRequest,
		ConfigService $configService,
		LoggerInterface $logger,
	) {
		$this->cacheActorService = $cacheActorService;
		$this->hashtagService = $hashtagService;
		$this->streamRequest = $streamRequest;
		$this->configService = $configService;
		$this->logger = $logger;
	}

	/**
	 * Full-text search over the statuses the viewer can see. The viewer bound
	 * comes from StreamRequest::setViewer(), set by the caller.
	 *
	 * $limit is how many rows a caller that pages its results needs; null asks
	 * for as many as the request returns on its own.
	 *
	 * @return \OCA\Social\Model\ActivityPub\Stream[]
	 */
	public function searchStreamContent(string $search, ?int $limit = null): array {
		$type = $this->getTypeFromSearch($search);
		if ($search === '' || !($type & self::SEARCH_CONTENT)) {
			return [];
		}

		if ($limit === null) {
			return $this->streamRequest->searchContent($search);
		}

		return $this->streamRequest->searchContent($search, $limit);
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
	 * @param int|null $limit how many accounts a paging caller needs, null for all of them
	 *
	 * @return Person[]
	 */
	public function searchAccounts(string $search, ?int $limit = null): array {
		$type = $this->getTypeFromSearch($search);

		if ($search === '' || !($type & self::SEARCH_ACCOUNTS)) {
			return [];
		}

		$search = ltrim($search, '@');

		try {
			// search and cache eventual exact account first
			$this->cacheActorService->getFromAccount($search);
		} catch (Exception $e) {
		}

		$accounts = $this->cacheActorService->searchCachedAccounts($search);

		// TODO: push the cut into CacheActorsRequest::searchAccounts() so the
		// database stops loading rows nobody asked for
		return ($limit === null) ? $accounts : array_slice($accounts, 0, $limit);
	}

	/**
	 * @param string $search
	 * @param int|null $limit how many hashtags a paging caller needs, null for all of them
	 *
	 * @return array
	 */
	public function searchHashtags(string $search, ?int $limit = null): array {
		$result = [];
		$type = $this->getTypeFromSearch($search);
		if ($search === '' || !($type & self::SEARCH_HASHTAGS)) {
			return $result;
		}

		if (substr($search, 0, 1) === '#') {
			$search = substr($search, 1);
		}

		$hashtags = $this->hashtagService->searchHashtags($search, true);

		// TODO: push the cut into HashtagsRequest::searchHashtags() so the
		// database stops loading rows nobody asked for
		return ($limit === null) ? $hashtags : array_slice($hashtags, 0, $limit);
	}

	/**
	 * @param string $search
	 *
	 * @return array
	 */

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
