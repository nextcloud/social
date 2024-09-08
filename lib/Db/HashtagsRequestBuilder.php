<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class HashtagsRequestBuilder
 *
 * @package OCA\Social\Db
 */
class HashtagsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;


	/**
	 * Base of the Sql Insert request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getHashtagsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_HASHTAGS);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getHashtagsUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_HASHTAGS);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getHashtagsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select('h.hashtag', 'h.trend')
		   ->from(self::TABLE_HASHTAGS, 'h');

		$this->defaultSelectAlias = 'h';
		$qb->setDefaultSelectAlias('h');

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getHashtagsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_HASHTAGS);

		return $qb;
	}


	/**
	 * @param array $data
	 *
	 * @return array
	 */
	public function parseHashtagsSelectSql(array $data): array {
		return [
			'hashtag' => $this->get('hashtag', $data, ''),
			'trend' => $this->getArray('trend', $data, [])
		];
	}
}
