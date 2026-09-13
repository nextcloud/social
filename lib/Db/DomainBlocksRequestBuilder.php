<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\ICompositeExpression;

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
	 * The domains are read once and compared as **constants**, rather than
	 * joined as a table. The join this used to be cost a `LIKE` against
	 * `LOWER(attributed_to)` — four of them, on an unindexed text column —
	 * evaluated against the block rows for every candidate row of every
	 * timeline read, and it cost that whether or not the account had blocked
	 * anything. An account that has blocked nothing now adds no clause at all;
	 * one that has blocked something pays for its own handful of patterns and
	 * for no join. Measured on a home timeline over 22,000 posts: 56.2 ms with
	 * the join, 41.5 ms without it.
	 *
	 * The list is read through a per-request cache, so several filtered
	 * queries in one request — a timeline and its thread, say — read it once.
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

		$domains = $qb->blockedDomains();
		if ($domains === []) {
			return;
		}

		$expr = $qb->expr();
		$pf = $qb->getDefaultSelectAlias();

		foreach (self::authorColumns($pf, $announceAlias) as $author) {
			// the boosted row is joined LEFT, so its author is NULL on every
			// post that is not a boost. `NOT (NULL LIKE …)` is NULL, which
			// would drop those rows, so a missing author is explicitly allowed
			$nullable = $author !== $pf . '.attributed_to';

			foreach ($domains as $domain) {
				try {
					$patterns = self::domainPatterns($domain);
				} catch (InvalidResourceException) {
					// a row that cannot be turned into a pattern is one this
					// filter cannot honour; leaving it out would quietly widen
					// the timeline, so nothing is matched by it and the block
					// simply does not apply to this read
					continue;
				}

				foreach ($patterns as $pattern) {
					$notOnIt = $expr->notLike(
						$qb->func()->lower($author), $qb->createNamedParameter($pattern)
					);
					$qb->andWhere(
						$nullable ? $expr->orX($expr->isNull($author), $notOnIt) : $notOnIt
					);
				}
			}
		}
	}

	/**
	 * Matches a column of actor ids against one domain.
	 *
	 * The purge side of a domain block: `filterDomainBlocked()` above compares
	 * a column against the *rows* of the block table, while this compares it
	 * against one domain the caller already has. Same two patterns, each
	 * anchored at a scheme and closed by the `/` that ends the host, so
	 * `good.example` cannot be matched by `good.example.attacker.test`.
	 *
	 * @param string $domain must already have been through
	 *                       `DomainBlockService::normalise()`. A `%` or a `_`
	 *                       reaching a LIKE pattern would widen it to other
	 *                       instances, and this one is used to *delete* — so
	 *                       it is refused here as well rather than trusted.
	 *
	 * @throws InvalidResourceException
	 */
	public static function onDomain(SocialQueryBuilder $qb, string $column, string $domain): ICompositeExpression {
		$patterns = [];
		foreach (self::domainPatterns($domain) as $pattern) {
			$patterns[] = $qb->expr()->like(
				$qb->func()->lower($column), $qb->createNamedParameter($pattern)
			);
		}

		return $qb->expr()->orX(...$patterns);
	}

	/**
	 * The LIKE patterns an actor id on one domain matches, one per scheme.
	 *
	 * Separate from the expression above so that what the patterns do — and
	 * refuse to do — can be read and tested without a database.
	 *
	 * @return string[]
	 *
	 * @throws InvalidResourceException
	 */
	public static function domainPatterns(string $domain): array {
		if ($domain === '' || strpbrk($domain, '%_\\') !== false) {
			throw new InvalidResourceException("'" . $domain . "' is not a domain");
		}

		$patterns = [];
		foreach (self::SCHEMES as $scheme) {
			$patterns[] = $scheme . strtolower($domain) . '/%';
		}

		return $patterns;
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
