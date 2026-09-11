<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class DomainBlocksRequestBuilder
 *
 * @package OCA\Social\Db
 */
class DomainBlocksRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * The schemes an actor id is written with. Each pattern is anchored at one
	 * of them and closed by the `/` that ends the host, so a blocked
	 * `good.example` cannot be matched by `good.example.attacker.test` and a
	 * host cannot be matched half way through.
	 */
	public const SCHEMES = ['https://', 'http://'];

	/**
	 * The columns a domain block is applied to: the author of the row, and the
	 * author of the row it boosts.
	 *
	 * A boost's own author is the booster, so without the second one a blocked
	 * instance still reaches the viewer through anybody who boosts it.
	 *
	 * @return string[]
	 */
	public static function authorColumns(string $alias, string $announceAlias): array {
		$columns = [$alias . '.attributed_to'];
		if ($announceAlias !== '') {
			$columns[] = $announceAlias . '.attributed_to';
		}

		return $columns;
	}

	/**
	 * Hides every post whose author is on an instance the viewer has blocked.
	 *
	 * One LEFT JOIN anti-join against the viewer's own rows, the same shape
	 * `filterHiddenActors()` uses for blocked and muted accounts — and called
	 * from it, so that a domain block reaches every timeline, thread and
	 * notification list a per-account block reaches, and reaches all of them at
	 * once.
	 *
	 * A domain is matched against the *host of the author's actor id*, because
	 * that is the one form of the author's instance every row involved already
	 * carries: `social_stream.attributed_to` is the full actor uri, so this
	 * needs no join to the actor cache to find out where a post came from. Two
	 * patterns, `https://` and `http://`, each anchored at the scheme and
	 * closed by the `/` that ends the host, so `good.example` cannot be matched
	 * by `good.example.attacker.test` — and `domain` cannot carry a `%` or a
	 * `_` (see `DomainBlockService::normalise()`), so nothing in the table can
	 * widen its own pattern.
	 *
	 * Costs an index probe per query for an account that has blocked no
	 * instance, and nothing else: the join has no rows to compare against.
	 *
	 * @param string $announceAlias the alias the boosted row is joined under —
	 *                              a boost's own author is the booster, so
	 *                              without it a blocked instance still reaches
	 *                              the viewer through anybody who boosts it.
	 *                              Empty to skip that half.
	 */
	public static function filterDomainBlocked(
		SocialCoreQueryBuilder $qb, string $announceAlias = 'hd_o',
	): void {
		if (!$qb->hasViewer()) {
			return;
		}

		$expr = $qb->expr();
		$pf = $qb->getDefaultSelectAlias();

		$patterns = [];
		foreach (self::authorColumns($pf, $announceAlias) as $author) {
			foreach (self::SCHEMES as $scheme) {
				$patterns[] = $expr->like(
					$qb->func()->lower($author),
					$qb->func()->concat(
						$qb->createNamedParameter($scheme),
						'dbk.domain',
						$qb->createNamedParameter('/%')
					)
				);
			}
		}

		$qb->leftJoin(
			$pf, self::TABLE_DOMAIN_BLOCKS, 'dbk',
			$expr->andX(
				$expr->eq('dbk.actor_id_prim', $qb->createNamedParameter($qb->prim($qb->getViewer()->getId()))),
				$expr->orX(...$patterns)
			)
		);
		$qb->andWhere($expr->isNull('dbk.id'));
	}

	protected function getDomainBlocksInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_DOMAIN_BLOCKS);

		return $qb;
	}

	protected function getDomainBlocksSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('db.id', 'db.actor_id_prim', 'db.domain', 'db.creation')
			->from(self::TABLE_DOMAIN_BLOCKS, 'db');

		$this->defaultSelectAlias = 'db';
		$qb->setDefaultSelectAlias('db');

		return $qb;
	}

	protected function getDomainBlocksDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_DOMAIN_BLOCKS);

		return $qb;
	}
}
