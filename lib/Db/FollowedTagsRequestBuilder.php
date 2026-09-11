<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class FollowedTagsRequestBuilder
 *
 * @package OCA\Social\Db
 */
class FollowedTagsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	protected function getFollowedTagsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_FOLLOWED_TAGS);

		return $qb;
	}

	protected function getFollowedTagsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('ft.id', 'ft.actor_id_prim', 'ft.hashtag', 'ft.creation')
			->from(self::TABLE_FOLLOWED_TAGS, 'ft');

		$this->defaultSelectAlias = 'ft';
		$qb->setDefaultSelectAlias('ft');

		return $qb;
	}

	protected function getFollowedTagsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_FOLLOWED_TAGS);

		return $qb;
	}

	/**
	 * A row as the rest of the app reads one: the tag, and the id the API
	 * pages by.
	 *
	 * @return array{id: int, hashtag: string, creation: int}
	 */
	protected function parseFollowedTagsSelectSql(array $data): array {
		$creation = $this->get('creation', $data);

		return [
			'id' => $this->getInt('id', $data),
			'hashtag' => $this->get('hashtag', $data),
			'creation' => ($creation === '') ? 0 : (int)strtotime($creation),
		];
	}
}
