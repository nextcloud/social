<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamCard;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * What this instance has been reacting to lately: trending statuses and
 * trending links.
 *
 * Read the same way the hashtag trends are read — an aggregate over rows the
 * app already keeps for their own reasons — but live rather than from a cron's
 * stored counters. A hashtag needs stored buckets because counting uses means
 * walking `social_stream_tag`, a table with a row per tag per post; a status
 * trend is a count of `social_action` rows against an indexed column, and a
 * link trend a count of `social_stream_card` rows, both of which are one
 * grouped query over a window.
 *
 * Public statuses only, in both. These are unauthenticated routes: a trend
 * computed over followers-only posts would report on them to the whole
 * internet even if it never showed one, because a count is a statement about
 * what exists.
 */
class TrendsRequest extends TrendsRequestBuilder {
	/**
	 * The most interacted-with public statuses of a window, as `nid`s in
	 * trend order.
	 *
	 * Only statuses that were interacted with at all appear: the join is
	 * inner, so a post nobody touched is not a zero at the end of the list but
	 * absent from it — the same rule `HashtagService::getTrending()` applies
	 * to a hashtag nobody used.
	 *
	 * @return int[]
	 */
	public function trendingStatusNids(int $since, int $limit, int $offset, bool $onlyMedia = false): array {
		$qb = $this->getTrendingStatusNidsSelectSql();
		$expr = $qb->expr();

		$qb->innerJoin(
			's', self::TABLE_ACTIONS, 'a',
			$expr->andX(
				$expr->eq('a.object_id_prim', 's.id_prim'),
				$expr->gt('a.creation', $qb->createNamedParameter(
					$this->dateOf($since), IQueryBuilder::PARAM_DATE
				))
			)
		);

		$qb->andWhere($expr->eq('s.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC)));
		// a boost and a notification are not statuses that can trend
		$qb->andWhere($expr->eq('s.type', $qb->createNamedParameter(Note::TYPE)));

		// what a picture-first discover screen shows: a text post is a fine
		// trending status and a poor thing to put in a grid of squares
		if ($onlyMedia) {
			$qb->limitToMedia();
		}

		$qb->selectAlias($qb->createFunction('COUNT(a.id_prim)'), 'interactions');
		$qb->groupBy('s.nid');
		$qb->orderBy('interactions', 'desc');
		$qb->addOrderBy('s.nid', 'desc');
		$qb->setMaxResults($limit);
		$qb->setFirstResult($offset);

		$nids = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$nids[] = (int)$data['nid'];
		}
		$cursor->closeCursor();

		return $nids;
	}

	/**
	 * The statuses of a page that has already been decided, in its order.
	 *
	 * The same shape as the list timeline's own reader and deliberately not a
	 * call into it: that method belongs to the lists, and this class does not
	 * own it.
	 *
	 * @param int[] $nids
	 *
	 * @return Stream[]
	 */
	public function statusesByNids(array $nids): array {
		if ($nids === []) {
			return [];
		}

		$qb = $this->getStreamSelectSql(Stream::FORMAT_LOCAL);
		$qb->andWhere(
			$qb->expr()->in('s.nid', $qb->createNamedParameter($nids, IQueryBuilder::PARAM_INT_ARRAY))
		);
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction('sa');

		$byNid = [];
		foreach ($this->getStreamsFromRequest($qb) as $status) {
			$byNid[$status->getNid()] = $status;
		}

		// the trend order is restored here rather than asked of the database:
		// there is no portable way to say "in the order of that IN list"
		$statuses = [];
		foreach ($nids as $nid) {
			if (isset($byNid[$nid])) {
				$statuses[] = $byNid[$nid];
			}
		}

		return $statuses;
	}

	/**
	 * The links most often attached to a public status inside a window, most
	 * shared first.
	 *
	 * Counted by url and not by card row: the same article posted by five
	 * accounts is five rows in `social_stream_card` and one trending link,
	 * which is the whole point of the route.
	 *
	 * @return array<array{url: string, shares: int}>
	 */
	public function trendingLinks(int $since, int $limit, int $offset): array {
		$qb = $this->getTrendingLinkUrlsSelectSql();
		$expr = $qb->expr();

		$qb->innerJoin(
			'sc', self::TABLE_STREAM, 's',
			$expr->andX(
				$expr->eq('s.id_prim', 'sc.stream_id_prim'),
				$expr->eq('s.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC)),
				$expr->gt('s.published_time', $qb->createNamedParameter(
					$this->dateOf($since), IQueryBuilder::PARAM_DATE
				))
			)
		);

		$qb->selectAlias($qb->createFunction('COUNT(sc.stream_id_prim)'), 'shares');
		$qb->groupBy('sc.url');
		$qb->orderBy('shares', 'desc');
		$qb->addOrderBy('sc.url', 'asc');
		$qb->setMaxResults($limit);
		$qb->setFirstResult($offset);

		$links = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$links[] = [
				'url' => (string)$data['url'],
				'shares' => (int)$data['shares'],
			];
		}
		$cursor->closeCursor();

		return $links;
	}

	/**
	 * The public posts carrying one url, newest first.
	 *
	 * Mastodon's link timeline. The url is matched exactly rather than by
	 * prefix: two pages of the same site are two links, and a prefix match
	 * would fold a whole domain into whichever of its pages happened to trend.
	 *
	 * @return int[] nids, newest first
	 */
	public function statusNidsForUrl(string $url, int $limit, int $maxId = 0, int $minId = 0): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$qb->selectDistinct('s.nid')
			->from(self::TABLE_STREAM_CARDS, 'sc')
			->innerJoin(
				'sc', self::TABLE_STREAM, 's',
				$expr->andX(
					$expr->eq('s.id_prim', 'sc.stream_id_prim'),
					$expr->eq('s.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC))
				)
			)
			->where($expr->eq('sc.url', $qb->createNamedParameter($url)))
			->orderBy('s.nid', 'desc')
			->setMaxResults(max(1, min(40, $limit)));

		if ($maxId > 0) {
			$qb->andWhere($expr->lt('s.nid', $qb->createNamedParameter($maxId, IQueryBuilder::PARAM_INT)));
		}

		if ($minId > 0) {
			$qb->andWhere($expr->gt('s.nid', $qb->createNamedParameter($minId, IQueryBuilder::PARAM_INT)));
		}

		$nids = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$nids[] = (int)$data['nid'];
		}
		$cursor->closeCursor();

		return $nids;
	}

	/**
	 * One stored card per url, so a trending link can be rendered with the
	 * title and image the preview already fetched.
	 *
	 * Any one of the rows will do — they are previews of the same page — so
	 * the first row seen for a url wins and the rest are dropped.
	 *
	 * @param string[] $urls
	 *
	 * @return array<string, StreamCard> by url
	 */
	public function cardsByUrls(array $urls): array {
		if ($urls === []) {
			return [];
		}

		$qb = $this->getTrendingCardsSelectSql();
		$qb->andWhere(
			$qb->expr()->in('sc.url', $qb->createNamedParameter($urls, IQueryBuilder::PARAM_STR_ARRAY))
		);

		$cards = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$card = $this->parseTrendingCardSelectSql($data);
			$cards[$card->getUrl()] ??= $card;
		}
		$cursor->closeCursor();

		return $cards;
	}

	/**
	 * A unix time as the date columns hold one. Both windows are compared
	 * against a DATETIME column, so the bound has to be one too — comparing a
	 * date against an integer is a full scan on MySQL and an error on
	 * PostgreSQL.
	 */
	private function dateOf(int $since): \DateTime {
		return (new \DateTime())->setTimestamp($since);
	}
}
