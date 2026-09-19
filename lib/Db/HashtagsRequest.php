<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Exceptions\HashtagDoesNotExistException;
use OCA\Social\Model\ActivityPub\Stream;
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
	 * How long a hashtag this table holds — the same as `social_stream_tag`,
	 * which is where these rows are counted from. A longer one has nowhere to
	 * go: `hashtag` is the primary key, so it can be neither truncated (two
	 * tags would become one row) nor stored.
	 */
	public const HASHTAG_MAX_LENGTH = 127;

	/**
	 * Writes one hashtag's trend, whether or not it already has a row.
	 *
	 * The caller cannot tell the two apart: the trends cron knows the rows that
	 * currently claim a trend, which is not the set of rows that exist — a
	 * hashtag that trended, fell to zero and trends again has a row and is not
	 * in that list. An INSERT for it is refused by the primary key, and the
	 * exception used to end the whole pass.
	 *
	 * The UPDATE goes first because almost every hashtag the cron writes has
	 * been seen before; it is followed by an insert that the database is asked
	 * to skip on a conflict, so a row this process did not know about costs a
	 * statement rather than a failed transaction — which on PostgreSQL takes
	 * every later statement with it.
	 */
	public function upsert(string $hashtag, array $trend): void {
		if ($this->update($hashtag, $trend) > 0) {
			return;
		}

		$values = [
			'hashtag' => $hashtag,
			'trend' => json_encode($trend),
		];
		foreach (self::TREND_COLUMNS as $period => $column) {
			$values[$column] = (int)($trend[$period] ?? 0);
		}

		$this->dbConnection->insertIgnoreConflict(self::TABLE_HASHTAGS, $values);
	}

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
	 * Rewrites one hashtag's trend, if it has a row.
	 *
	 * @return int how many rows were written — 0 when there is no such
	 *             hashtag, and on MySQL also when the row already held these
	 *             counts
	 */
	public function update(string $hashtag, array $trend): int {
		$qb = $this->getHashtagsUpdateSql();
		$qb->set('trend', $qb->createNamedParameter(json_encode($trend)));
		foreach (self::TREND_COLUMNS as $period => $column) {
			$qb->set($column, $qb->createNamedParameter(
				(int)($trend[$period] ?? 0), IQueryBuilder::PARAM_INT
			));
		}
		$qb->limitToHashtag($hashtag);

		return $qb->executeStatement();
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

		// built first and passed in one go: an empty orX() is deprecated and
		// will throw
		$windows = [];
		foreach (self::TREND_COLUMNS as $column) {
			$windows[] = $qb->expr()->gt('h.' . $column, $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT));
		}
		$qb->andWhere($qb->expr()->orX(...$windows));

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

		$qb->limitToHashtag($hashtag);

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
	/**
	 * The hashtags that travel with one: those on the same public posts,
	 * most often first.
	 *
	 * Counted from `social_stream_tag` joined to itself on the post, and to
	 * the post for its visibility: a tag on a followers-only post is not
	 * public knowledge, so it does not count here, however often it recurs.
	 *
	 * @return array<string, int> hashtag => how many public posts carry both
	 */
	public function related(string $hashtag, int $limit = 20): array {
		$hashtag = strtolower(trim(ltrim($hashtag, '#')));
		if ($hashtag === '' || $limit < 1) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$qb->select('other.hashtag')
			->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_STREAM_TAGS, 'st')
			->innerJoin('st', self::TABLE_STREAM_TAGS, 'other', $expr->andX(
				$expr->eq('other.stream_id', 'st.stream_id'),
				$expr->neq('other.hashtag', 'st.hashtag')
			))
			->innerJoin('st', self::TABLE_STREAM, 's', $expr->eq('s.id_prim', 'st.stream_id'))
			->where($expr->eq('st.hashtag', $qb->createNamedParameter($hashtag)))
			->andWhere($expr->eq('s.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC)))
			->groupBy('other.hashtag')
			->orderBy('total', 'desc')
			->addOrderBy('other.hashtag', 'asc')
			->setMaxResults($limit);

		$related = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$name = (string)$data['hashtag'];
			if ($name !== '') {
				$related[$name] = (int)$data['total'];
			}
		}
		$cursor->closeCursor();

		return $related;
	}

	public function searchHashtags(string $hashtag, bool $all): array {
		$qb = $this->getHashtagsSelectSql();
		$qb->searchInHashtag($hashtag, $all);
		$qb->limitResults(25);

		$hashtags = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$hashtags[] = $this->parseHashtagsSelectSql($data);
		}
		$cursor->closeCursor();

		return $hashtags;
	}
}
