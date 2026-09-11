<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use DateTimeZone;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\FilterKeyword;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The keyword filters of an account, and the keywords of each.
 *
 * Every read here takes the owner's actor id and compares it in SQL. That is
 * the whole of the access control on filters: nothing in this class can return
 * a filter, or a keyword of a filter, that belongs to somebody else, so no
 * caller has to remember to check.
 */
class FiltersRequest extends FiltersRequestBuilder {
	/**
	 * @return int the id the filter was stored under
	 */
	public function save(Filter $filter): int {
		$qb = $this->getFiltersInsertSql();
		$qb->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($filter->getActorId())))
			->setValue('title', $qb->createNamedParameter($filter->getTitle()))
			->setValue('contexts', $qb->createNamedParameter($filter->exportContexts()))
			->setValue('action', $qb->createNamedParameter($filter->getAction()))
			->setValue(
				'expires_at',
				$qb->createNamedParameter($this->dateTime($filter->getExpiresAt()), IQueryBuilder::PARAM_DATE)
			)
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$qb->executeStatement();
		$id = $qb->getLastInsertId();
		$filter->setId($id);
		if ($filter->getCreation() === 0) {
			$filter->setCreation(time());
		}

		foreach ($filter->getKeywords() as $keyword) {
			$this->saveKeyword($keyword->setFilterId($id));
		}

		return $id;
	}

	/** The filter itself; its keywords are written one by one. */
	public function update(Filter $filter): void {
		$qb = $this->getFiltersUpdateSql();
		$qb->set('title', $qb->createNamedParameter($filter->getTitle()))
			->set('contexts', $qb->createNamedParameter($filter->exportContexts()))
			->set('action', $qb->createNamedParameter($filter->getAction()))
			->set(
				'expires_at',
				$qb->createNamedParameter($this->dateTime($filter->getExpiresAt()), IQueryBuilder::PARAM_DATE)
			);
		$qb->where(
			$qb->expr()->eq('id', $qb->createNamedParameter($filter->getId(), IQueryBuilder::PARAM_INT)),
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($filter->getActorId())))
		);

		$qb->executeStatement();
	}

	/**
	 * One filter of one account, keywords included.
	 *
	 * @throws ItemNotFoundException there is no such filter *of this account*,
	 *                               which is also the answer for one that
	 *                               belongs to somebody else
	 */
	public function getById(int $id, string $actorId): Filter {
		$qb = $this->getFiltersSelectSql();
		$qb->andWhere($qb->expr()->eq('f.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('f.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ItemNotFoundException('filter not found');
		}

		$filter = $this->parseFiltersSelectSql($data)->setActorId($actorId);
		$keywords = $this->keywordsOf([$filter->getId()]);
		$filter->setKeywords($keywords[$filter->getId()] ?? []);

		return $filter;
	}

	/**
	 * Every filter of the account, newest first, keywords included.
	 *
	 * @return Filter[]
	 */
	public function getByActor(string $actorId): array {
		return $this->withKeywords($this->selectByActor($actorId), $actorId);
	}

	/**
	 * The filters that still apply, as of `$now`.
	 *
	 * The expiry is a predicate of the read and not a row that something
	 * deletes: a filter stops applying the second it expires, on an instance
	 * with no working cron as much as on one with.
	 *
	 * @return Filter[]
	 */
	public function getActiveByActor(string $actorId, ?int $now = null): array {
		$qb = $this->selectByActor($actorId);
		$qb->andWhere(
			$qb->expr()->orX(
				$qb->expr()->isNull('f.expires_at'),
				$qb->expr()->gt(
					'f.expires_at',
					$qb->createNamedParameter($this->dateTime($now ?? time()), IQueryBuilder::PARAM_DATE)
				)
			)
		);

		return $this->withKeywords($qb, $actorId);
	}

	/** Deleting a filter deletes the keywords that only existed for it. */
	public function delete(int $id, string $actorId): void {
		$qb = $this->getFiltersDeleteSql();
		$qb->where(
			$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
		);

		if ($qb->executeStatement() === 0) {
			// somebody else's filter, or none: its keywords are not this
			// caller's to delete either
			return;
		}

		$this->deleteKeywordsOfFilter($id);
	}

	/**
	 * Everything an account leaves behind here when it is deleted. Called from
	 * the account-deletion path, like every other deleteRelatedId().
	 */
	public function deleteRelatedId(string $actorId): void {
		foreach ($this->selectIdsByActor($actorId) as $id) {
			$this->deleteKeywordsOfFilter($id);
		}

		$qb = $this->getFiltersDeleteSql();
		$qb->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));
		$qb->executeStatement();
	}

	public function saveKeyword(FilterKeyword $keyword): int {
		$qb = $this->getKeywordsInsertSql();
		$qb->setValue('filter_id', $qb->createNamedParameter($keyword->getFilterId(), IQueryBuilder::PARAM_INT))
			->setValue('keyword', $qb->createNamedParameter($keyword->getKeyword()))
			->setValue('whole_word', $qb->createNamedParameter($keyword->isWholeWord() ? 1 : 0, IQueryBuilder::PARAM_INT))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$qb->executeStatement();
		$id = $qb->getLastInsertId();
		$keyword->setId($id);

		return $id;
	}

	public function updateKeyword(FilterKeyword $keyword): void {
		$qb = $this->getKeywordsUpdateSql();
		$qb->set('keyword', $qb->createNamedParameter($keyword->getKeyword()))
			->set('whole_word', $qb->createNamedParameter($keyword->isWholeWord() ? 1 : 0, IQueryBuilder::PARAM_INT));
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($keyword->getId(), IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * @throws ItemNotFoundException no such keyword on any filter of this account
	 */
	public function getKeywordById(int $id, string $actorId): FilterKeyword {
		$qb = $this->getOwnedKeywordSelectSql($actorId);
		$qb->andWhere($qb->expr()->eq('fk.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ItemNotFoundException('filter keyword not found');
		}

		return $this->parseKeywordsSelectSql($data);
	}

	public function deleteKeyword(int $id, string $actorId): void {
		// the ownership of a keyword is its filter's, and a DELETE cannot join:
		// the row is read under the join first, and nothing is deleted when
		// that read finds nothing
		$keyword = $this->getKeywordById($id, $actorId);

		$qb = $this->getKeywordsDeleteSql();
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($keyword->getId(), IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	public function deleteKeywordsOfFilter(int $filterId): void {
		$qb = $this->getKeywordsDeleteSql();
		$qb->where($qb->expr()->eq('filter_id', $qb->createNamedParameter($filterId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * The keywords of a set of filters, in one query rather than one per
	 * filter: a timeline read asks for every filter of the viewer at once.
	 *
	 * @param int[] $filterIds
	 *
	 * @return array<int, FilterKeyword[]> filter id => its keywords
	 */
	public function keywordsOf(array $filterIds): array {
		if ($filterIds === []) {
			return [];
		}

		$qb = $this->getKeywordsSelectSql();
		$qb->andWhere(
			$qb->expr()->in('fk.filter_id', $qb->createNamedParameter($filterIds, IQueryBuilder::PARAM_INT_ARRAY))
		);
		$qb->orderBy('fk.id', 'asc');

		$keywords = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$keyword = $this->parseKeywordsSelectSql($data);
			$keywords[$keyword->getFilterId()][] = $keyword;
		}
		$cursor->closeCursor();

		return $keywords;
	}

	private function selectByActor(string $actorId): SocialQueryBuilder {
		$qb = $this->getFiltersSelectSql();
		$qb->andWhere($qb->expr()->eq('f.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));
		$qb->orderBy('f.id', 'desc');

		return $qb;
	}

	/**
	 * @return int[]
	 */
	private function selectIdsByActor(string $actorId): array {
		$ids = [];
		$cursor = $this->selectByActor($actorId)->executeQuery();
		while ($data = $cursor->fetch()) {
			$ids[] = $this->getInt('id', $data);
		}
		$cursor->closeCursor();

		return $ids;
	}

	/**
	 * @return Filter[]
	 */
	private function withKeywords(SocialQueryBuilder $qb, string $actorId): array {
		$filters = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$filter = $this->parseFiltersSelectSql($data)->setActorId($actorId);
			$filters[$filter->getId()] = $filter;
		}
		$cursor->closeCursor();

		if ($filters === []) {
			return [];
		}

		$keywords = $this->keywordsOf(array_keys($filters));
		foreach ($filters as $id => $filter) {
			$filter->setKeywords($keywords[$id] ?? []);
		}

		return array_values($filters);
	}

	/**
	 * A timestamp as the date columns here hold one.
	 *
	 * Every other date in this schema is written from `new DateTime('now')`,
	 * which carries the server's timezone, and DBAL formats a DateTime in
	 * whatever zone the object has. A `DateTime('@…')` is always UTC, so an
	 * expiry built from a timestamp would be stored hours away from the `now`
	 * it is compared with on any instance that is not on UTC.
	 */
	private function dateTime(int $timestamp): ?DateTime {
		if ($timestamp === 0) {
			return null;
		}

		return (new DateTime('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
	}
}
