<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class DiscoveryRequestBuilder
 *
 * Extends CacheActorsRequestBuilder because everything discovery answers with
 * is an Account entity, and `social_cache_actor` is where this app builds one
 * from — including for its own local accounts, which are mirrored into it. The
 * `discoverable` flag is not there, though: it lives on `social_actor`, which
 * only local accounts have a row in, so every query here that honours the flag
 * joins the two.
 *
 * Both readers work in two steps, the way the timelines do: one query decides
 * *which* accounts belong in the answer and projects a single column, and a
 * second fetches the rows. The deciding query aggregates, and grouping by the
 * twenty columns an Account needs would be unportable — Oracle cannot group by
 * a CLOB at all.
 *
 * @package OCA\Social\Db
 */
class DiscoveryRequestBuilder extends CacheActorsRequestBuilder {
	use TArrayTools;

	/** The Account entities of a set of accounts already decided on. */
	protected function getDiscoveryActorsSelectSql(): SocialQueryBuilder {
		return $this->getCacheActorsSelectSql(Stream::FORMAT_LOCAL);
	}

	/**
	 * The local accounts that have opted in to being listed, as a single
	 * `id_prim` column.
	 *
	 * The join is what reads the `discoverable` flag — stored and federated
	 * since `Version1000Date20260911000002` and, until this, read by nothing:
	 * an account that never opted in was as listable as one that did, because
	 * there was no directory to leave it out of.
	 */
	protected function getDiscoverablePrimsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('a.id_prim')
			->from(self::TABLE_ACTORS, 'a');
		$qb->andWhere($qb->expr()->eq('a.discoverable', $qb->createNamedParameter(1)));

		$this->defaultSelectAlias = 'a';
		$qb->setDefaultSelectAlias('a');

		return $qb;
	}

	/**
	 * The follows of one account, as a single `object_id_prim` column.
	 *
	 * Accepted follows only: a follow request the other side has not answered
	 * says nothing about who that account trusts, and a pending request to a
	 * locked account would otherwise leak its followers into a suggestion
	 * list.
	 */
	protected function getFollowedPrimsSelectSql(string $alias): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select($alias . '.object_id_prim')
			->from(self::TABLE_FOLLOWS, $alias);
		$qb->andWhere($qb->expr()->eq($alias . '.accepted', $qb->createNamedParameter(1)));

		$this->defaultSelectAlias = $alias;
		$qb->setDefaultSelectAlias($alias);

		return $qb;
	}
}
