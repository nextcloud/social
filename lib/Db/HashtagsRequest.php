<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Exceptions\HashtagDoesNotExistException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Class HashtagsRequest
 *
 * @package OCA\Social\Db
 */
class HashtagsRequest extends HashtagsRequestBuilder {
	use TArrayTools;

	/**
	 * The trend window each sortable counter column holds. The same counts are
	 * kept in the `trend` JSON column, which is what the API hands back; these
	 * exist so the database can order and cut the list itself.
	 */
	public const TREND_COLUMNS = [
		'1h' => 'trend_1h',
		'12h' => 'trend_12h',
		'1d' => 'trend_1d',
		'3d' => 'trend_3d',
		'10d' => 'trend_10d',
	];

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
		foreach (self::TREND_COLUMNS as $period => $column) {
			$qb->setValue($column, $qb->createNamedParameter(
				(int)($trend[$period] ?? 0), IQueryBuilder::PARAM_INT
			));
		}

		$qb->executeStatement();
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
		foreach (self::TREND_COLUMNS as $period => $column) {
			$qb->set($column, $qb->createNamedParameter(
				(int)($trend[$period] ?? 0), IQueryBuilder::PARAM_INT
			));
		}
		$this->limitToHashtag($qb, $hashtag);

		$qb->executeStatement();
	}

	/**
	 * Rows carry a `counters` entry next to the JSON `trend`: the same numbers
	 * as the sortable columns hold them. The trends cron needs to see the two
	 * disagree — a row upgraded from a version that had no columns has a
	 * correct JSON and zeroed columns, and nothing else would ever notice.
	 *
	 * @param int $limit 0 for every row — what the trends cron needs, since it
	 *                   has to know which hashtags already have a row
	 *
	 * @return array
	 */
	public function getAll(int $limit = 0): array {
		$qb = $this->getHashtagsSelectSql();
		foreach (self::TREND_COLUMNS as $column) {
			$qb->addSelect('h.' . $column);
		}
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}

		$hashtags = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$hashtags[] = array_merge(
				$this->parseHashtagsSelectSql($data),
				['counters' => self::countersFromRow($data)]
			);
		}
		$cursor->closeCursor();

		return $hashtags;
	}

	/**
	 * The hashtags that currently claim a trend — the only rows the trends cron
	 * can act on.
	 *
	 * It used to read the whole table for this, on every run, to find the few
	 * rows that have fallen out of the widest window and need their counters
	 * cleared. Everything else it read was a hashtag with nothing to change.
	 * The set this returns is inherently small: it is the trending list.
	 *
	 * @return array
	 */
	public function getWithAnyTrend(int $limit = 0): array {
		$qb = $this->getHashtagsSelectSql();
		foreach (self::TREND_COLUMNS as $column) {
			$qb->addSelect('h.' . $column);
		}

		$anyWindow = $qb->expr()->orX();
		foreach (self::TREND_COLUMNS as $column) {
			$anyWindow->add($qb->expr()->gt('h.' . $column, $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		}
		$qb->andWhere($anyWindow);

		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}

		$hashtags = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$hashtags[] = array_merge(
				$this->parseHashtagsSelectSql($data),
				['counters' => self::countersFromRow($data)]
			);
		}
		$cursor->closeCursor();

		return $hashtags;
	}

	/**
	 * The sortable counters of one row, keyed by window.
	 *
	 * @return array<string, int>
	 */
	public static function countersFromRow(array $data): array {
		$counters = [];
		foreach (self::TREND_COLUMNS as $period => $column) {
			$counters[$period] = (int)($data[$column] ?? 0);
		}

		return $counters;
	}

	/**
	 * The most used hashtags within one window, most used first.
	 *
	 * Ordered and cut in the database. The counts live in a JSON column as
	 * well, which is what the API hands back, but no supported database can be
	 * asked to sort on that — so answering this used to mean loading every
	 * hashtag the instance has ever seen into PHP, on every trends request.
	 *
	 * @param string $period one of the windows in self::TREND_COLUMNS
	 *
	 * @return array[] [['hashtag' => string, 'trend' => array], …]
	 */
	public function getTrending(string $period, int $limit): array {
		$column = self::TREND_COLUMNS[$period] ?? null;
		if ($column === null) {
			return [];
		}

		$qb = $this->getHashtagsSelectSql();
		$qb->andWhere($qb->expr()->gt(
			'h.' . $column, $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)
		));
		$qb->orderBy('h.' . $column, 'desc');
		$qb->addOrderBy('h.hashtag', 'asc');
		$qb->setMaxResults(max(1, $limit));

		$hashtags = [];
		$cursor = $qb->executeQuery();
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

		$cursor = $qb->executeQuery();
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
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$hashtags[] = $this->parseHashtagsSelectSql($data);
		}
		$cursor->closeCursor();

		return $hashtags;
	}
}
