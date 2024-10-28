<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Exceptions\HashtagDoesNotExistException;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class HashtagsRequest
 *
 * @package OCA\Social\Db
 */
class HashtagsRequest extends HashtagsRequestBuilder {
	use TArrayTools;


	/**
	 * Insert a new Hashtag.
	 *
	 * @param string $hashtag
	 * @param array $trend
	 */
	public function save(string $hashtag, array $trend) {
		$qb = $this->getHashtagsInsertSql();
		$qb->setValue('hashtag', $qb->createNamedParameter($hashtag))
			->setValue('trend', $qb->createNamedParameter(json_encode($trend)));

		$qb->execute();
	}


	/**
	 * Insert a new Hashtag.
	 *
	 * @param string $hashtag
	 * @param array $trend
	 */
	public function update(string $hashtag, array $trend) {
		$qb = $this->getHashtagsUpdateSql();
		$qb->set('trend', $qb->createNamedParameter(json_encode($trend)));
		$this->limitToHashtag($qb, $hashtag);

		$qb->execute();
	}


	/**
	 * @return array
	 */
	public function getAll(): array {
		$qb = $this->getHashtagsSelectSql();

		$hashtags = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$hashtags[] = $this->parseHashtagsSelectSql($data);
		}
		$cursor->closeCursor();

		return $hashtags;
	}


	/**
	 * @param string $hashtag
	 *
	 * @return array
	 * @throws HashtagDoesNotExistException
	 */
	public function getHashtag(string $hashtag): array {
		$qb = $this->getHashtagsSelectSql();

		$this->limitToHashtag($qb, $hashtag);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new HashtagDoesNotExistException();
		}

		return $this->parseHashtagsSelectSql($data);
	}


	/**
	 * @param string $hashtag
	 * @param bool $all
	 *
	 * @return array
	 */
	public function searchHashtags(string $hashtag, bool $all): array {
		$qb = $this->getHashtagsSelectSql();
		$this->searchInHashtag($qb, $hashtag, $all);
		$this->limitResults($qb, 25);

		$hashtags = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$hashtags[] = $this->parseHashtagsSelectSql($data);
		}
		$cursor->closeCursor();

		return $hashtags;
	}
}
