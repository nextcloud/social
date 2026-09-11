<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\FilterKeyword;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class FiltersRequestBuilder
 *
 * @package OCA\Social\Db
 */
class FiltersRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * The two table names live here rather than beside the others in
	 * `CoreRequestBuilder` because a `TABLE_*` constant there is a claim three
	 * other places have to honour at once — `CoreRequestBuilder::$tables`, the
	 * schema table of `docs/Architecture.md` and `occ social:reset` — and this
	 * change may not edit those files. Moving them up is one commit; until it
	 * happens, a reset leaves these two tables behind.
	 */

	protected function getFiltersInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_FILTERS);

		return $qb;
	}

	protected function getFiltersUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_FILTERS);

		return $qb;
	}

	protected function getFiltersSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('f.id', 'f.actor_id_prim', 'f.title', 'f.contexts', 'f.action', 'f.expires_at', 'f.creation')
			->from(self::TABLE_FILTERS, 'f');

		$this->defaultSelectAlias = 'f';
		$qb->setDefaultSelectAlias('f');

		return $qb;
	}

	protected function getFiltersDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_FILTERS);

		return $qb;
	}

	protected function getKeywordsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_FILTER_KEYWORDS);

		return $qb;
	}

	protected function getKeywordsUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_FILTER_KEYWORDS);

		return $qb;
	}

	protected function getKeywordsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('fk.id', 'fk.filter_id', 'fk.keyword', 'fk.whole_word', 'fk.creation')
			->from(self::TABLE_FILTER_KEYWORDS, 'fk');

		$this->defaultSelectAlias = 'fk';
		$qb->setDefaultSelectAlias('fk');

		return $qb;
	}

	protected function getKeywordsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_FILTER_KEYWORDS);

		return $qb;
	}

	/**
	 * A keyword is owned by whoever owns its filter, so the ownership check is
	 * this join rather than a second query: a request that names somebody
	 * else's keyword has to come back empty, not come back and be checked.
	 */
	protected function getOwnedKeywordSelectSql(string $actorId): SocialQueryBuilder {
		$qb = $this->getKeywordsSelectSql();
		$qb->innerJoin(
			'fk',
			self::TABLE_FILTERS,
			'f',
			$qb->expr()->andX(
				$qb->expr()->eq('fk.filter_id', 'f.id'),
				$qb->expr()->eq('f.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			)
		);

		return $qb;
	}

	protected function parseFiltersSelectSql(array $data): Filter {
		$filter = new Filter();
		$filter->setId($this->getInt('id', $data))
			->setTitle($this->get('title', $data))
			->setAction($this->get('action', $data, Filter::ACTION_WARN))
			->importContexts($this->get('contexts', $data));

		$expiresAt = $this->get('expires_at', $data);
		if ($expiresAt !== '') {
			$filter->setExpiresAt((int)strtotime($expiresAt));
		}

		$creation = $this->get('creation', $data);
		if ($creation !== '') {
			$filter->setCreation((int)strtotime($creation));
		}

		return $filter;
	}

	protected function parseKeywordsSelectSql(array $data): FilterKeyword {
		$keyword = new FilterKeyword();
		$keyword->setId($this->getInt('id', $data))
			->setFilterId($this->getInt('filter_id', $data))
			->setKeyword($this->get('keyword', $data))
			->setWholeWord($this->getInt('whole_word', $data) === 1);

		$creation = $this->get('creation', $data);
		if ($creation !== '') {
			$keyword->setCreation((int)strtotime($creation));
		}

		return $keyword;
	}
}
