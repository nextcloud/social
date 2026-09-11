<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Who this instance is willing to point at: the profile directory, and the
 * accounts suggested to somebody who is looking for people to follow.
 *
 * Both are reads of data the app already keeps — the opt-in flag on
 * `social_actor`, the follow graph, and when each account last posted. Nothing
 * here scores anything: a suggestion is "somebody the people you follow
 * follow", ordered by how many of them do, and the directory is "everybody who
 * asked to be listed", ordered by how recently they posted.
 *
 * Every method that orders by activity orders by `MAX(s.nid)` and not by
 * `MAX(s.published_time)`. `nid` rises with insertion, so it says the same
 * thing; it is an integer, so `COALESCE(…, 0)` gives an account that has never
 * posted a real value and sorts it last on every platform, where a NULL date
 * sorts first on PostgreSQL and last on MySQL.
 */
class DiscoveryRequest extends DiscoveryRequestBuilder {
	/** Newest account first. */
	public const ORDER_NEW = 'new';
	/** Most recently posted first, which is Mastodon's default. */
	public const ORDER_ACTIVE = 'active';

	/**
	 * The page of the directory, as `id_prim`s in the order they are to be
	 * shown.
	 *
	 * Only accounts that opted in: the `discoverable` flag is a predicate of
	 * the statement, so an account that did not opt in is not a row that was
	 * read and then dropped.
	 *
	 * @return string[]
	 */
	public function directoryPrims(string $order, int $limit, int $offset): array {
		$qb = $this->getDiscoverablePrimsSelectSql();

		if ($order === self::ORDER_NEW) {
			$qb->orderBy('a.creation', 'desc');
		} else {
			$this->orderByLastStatus($qb, 'a.id_prim');
			$qb->groupBy('a.id_prim');
		}

		$qb->setMaxResults($limit);
		$qb->setFirstResult($offset);

		return $this->primsFromRequest($qb, 'id_prim');
	}

	/**
	 * The accounts followed by the accounts the viewer follows, most-followed
	 * first.
	 *
	 * Two hops over `social_follow` and nothing else: this is the follow graph
	 * the app already has, read as "people you are one step away from". The
	 * count is how many of the viewer's own follows follow each candidate,
	 * which is the only ranking here and is a fact rather than a score.
	 *
	 * The viewer's own follows and the viewer themselves are excluded in the
	 * statement, so they never occupy a slot in the page that is then thrown
	 * away by the caller.
	 *
	 * @return string[]
	 */
	public function friendsOfFriendsPrims(string $viewerId, int $limit): array {
		$qb = $this->getFollowedPrimsSelectSql('f2');
		$expr = $qb->expr();
		$viewerPrim = $qb->prim($viewerId);

		$qb->innerJoin(
			'f2', self::TABLE_FOLLOWS, 'f1',
			$expr->andX(
				$expr->eq('f1.object_id_prim', 'f2.actor_id_prim'),
				$expr->eq('f1.actor_id_prim', $qb->createNamedParameter($viewerPrim)),
				$expr->eq('f1.accepted', $qb->createNamedParameter(1))
			)
		);

		// not yourself, and not the people you already follow
		$qb->andWhere($expr->neq('f2.object_id_prim', $qb->createNamedParameter($viewerPrim)));
		$qb->andWhere(
			$expr->notIn(
				'f2.object_id_prim',
				$qb->createNamedParameter(
					$this->followedPrims($viewerId) ?: [''],
					IQueryBuilder::PARAM_STR_ARRAY
				)
			)
		);

		$qb->selectAlias($qb->createFunction('COUNT(f2.object_id_prim)'), 'mutuals');
		$qb->groupBy('f2.object_id_prim');
		$qb->orderBy('mutuals', 'desc');
		$qb->setMaxResults($limit);

		return $this->primsFromRequest($qb, 'object_id_prim');
	}

	/**
	 * Local accounts that opted in to being listed, most recently active
	 * first.
	 *
	 * What the suggestions fall back to when the follow graph has nothing to
	 * say — a new account follows nobody, so it has no friends of friends, and
	 * an empty suggestion list is what a client draws as "nobody to follow
	 * here".
	 *
	 * @return string[]
	 */
	public function activeLocalPrims(int $limit): array {
		return $this->directoryPrims(self::ORDER_ACTIVE, $limit, 0);
	}

	/**
	 * The accounts the viewer follows, accepted or not.
	 *
	 * Pending requests are included here on purpose, unlike in the graph walk
	 * above: this list is used to *exclude*, and suggesting somebody whose
	 * answer you are waiting on is as wrong as suggesting somebody you already
	 * follow.
	 *
	 * @return string[]
	 */
	public function followedPrims(string $viewerId): array {
		$qb = $this->getQueryBuilder();
		$qb->select('f.object_id_prim')
			->from(self::TABLE_FOLLOWS, 'f');
		$qb->andWhere(
			$qb->expr()->eq('f.actor_id_prim', $qb->createNamedParameter($qb->prim($viewerId)))
		);

		return $this->primsFromRequest($qb, 'object_id_prim');
	}

	/**
	 * The accounts the viewer has blocked or muted, and the accounts that have
	 * blocked the viewer.
	 *
	 * All three directions, because all three are accounts this instance must
	 * not put in front of them: one they chose not to see, and one that chose
	 * not to be seen by them.
	 *
	 * @param string[] $types ActorRelation::TYPE_* values
	 *
	 * @return string[]
	 */
	public function relatedPrims(string $viewerId, array $types): array {
		if ($types === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$qb->select('ar.object_id_prim')
			->from(self::TABLE_ACTOR_RELATION, 'ar');
		$qb->andWhere(
			$qb->expr()->eq('ar.actor_id_prim', $qb->createNamedParameter($qb->prim($viewerId)))
		);
		$qb->andWhere(
			$qb->expr()->in('ar.type', $qb->createNamedParameter($types, IQueryBuilder::PARAM_STR_ARRAY))
		);

		return $this->primsFromRequest($qb, 'object_id_prim');
	}

	/**
	 * The Account entities of accounts already decided on, in the order they
	 * were decided.
	 *
	 * The order is restored here rather than asked of the database: the second
	 * query has no way to express "in the order of that `IN` list" portably,
	 * and an account whose cache row is missing simply drops out of the page
	 * instead of arriving half-filled.
	 *
	 * @param string[] $prims
	 *
	 * @return Person[]
	 */
	public function actorsByPrims(array $prims): array {
		if ($prims === []) {
			return [];
		}

		$byPrim = [];
		foreach ($this->fetchActors($prims) as $actor) {
			$byPrim[md5($actor->getId())] = $actor;
		}

		$actors = [];
		foreach ($prims as $prim) {
			if (isset($byPrim[$prim])) {
				$actor = $byPrim[$prim];
				$actor->setExportFormat(Stream::FORMAT_LOCAL);
				$actors[] = $actor;
			}
		}

		return $actors;
	}

	/**
	 * The cached profiles of a set of accounts, in whatever order the database
	 * returns them.
	 *
	 * Separate from the ordering above for the same reason the timelines split
	 * their two queries: this half is the statement, and the half above is
	 * what the page is made of.
	 *
	 * @param string[] $prims
	 *
	 * @return Person[]
	 */
	protected function fetchActors(array $prims): array {
		$qb = $this->getDiscoveryActorsSelectSql();
		$qb->andWhere(
			$qb->expr()->in('ca.id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY))
		);

		return $this->getCacheActorsFromRequest($qb);
	}

	/**
	 * Orders a statement by when the account last posted in public, newest
	 * first, with accounts that never have at the end.
	 *
	 * The join is left, not inner: an account that has opted in to the
	 * directory and not yet posted still belongs in it.
	 */
	private function orderByLastStatus(SocialQueryBuilder $qb, string $actorColumn): void {
		$expr = $qb->expr();
		$qb->leftJoin(
			'a', self::TABLE_STREAM, 's',
			$expr->andX(
				$expr->eq('s.attributed_to_prim', $actorColumn),
				$expr->eq('s.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC))
			)
		);

		$qb->selectAlias($qb->createFunction('COALESCE(MAX(s.nid), 0)'), 'last_status');
		$qb->orderBy('last_status', 'desc');
	}

	/**
	 * @return string[] the named column of every row, blanks dropped
	 */
	private function primsFromRequest(SocialQueryBuilder $qb, string $column): array {
		$prims = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$prim = (string)($data[$column] ?? '');
			if ($prim !== '') {
				$prims[] = $prim;
			}
		}
		$cursor->closeCursor();

		return $prims;
	}
}
