<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\Client\FeaturedTag;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class FeaturedTagsRequestBuilder
 *
 * @package OCA\Social\Db
 */
class FeaturedTagsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * Declared here rather than in CoreRequestBuilder's table list, which is
	 * what `emptyAll()`/`uninstall()` walk: adding it there is a change to a
	 * file this feature does not own.
	 */
	public const TABLE_FEATURED_TAGS = 'social_featured_tag';

	protected function getFeaturedTagsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_FEATURED_TAGS);

		return $qb;
	}

	protected function getFeaturedTagsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('ft.id', 'ft.actor_id', 'ft.actor_id_prim', 'ft.hashtag', 'ft.creation')
			->from(self::TABLE_FEATURED_TAGS, 'ft');

		$this->defaultSelectAlias = 'ft';
		$qb->setDefaultSelectAlias('ft');

		return $qb;
	}

	protected function getFeaturedTagsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_FEATURED_TAGS);

		return $qb;
	}

	protected function parseFeaturedTagsSelectSql(array $data): FeaturedTag {
		return (new FeaturedTag())->importFromDatabase($data);
	}
}
