<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use DateTimeZone;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Class MuteExpiryRequestBuilder
 *
 * @package OCA\Social\Db
 */
class MuteExpiryRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * The table name lives here rather than beside the others in
	 * `CoreRequestBuilder` because a `TABLE_*` constant there is a claim three
	 * other places have to honour at once — `CoreRequestBuilder::$tables`, the
	 * schema table of `docs/Architecture.md` and `occ social:reset` — and this
	 * change may not edit those files. Moving it up is one commit; until it
	 * happens, a reset leaves this table behind.
	 */
	public const TABLE_MUTE_EXPIRY = 'social_mute_expiry';

	/** The alias the expiry rows of a timeline query are joined under. */
	public const ALIAS = 'hd_x';

	/**
	 * The columns an expiry is looked up by: the author of the row, and the
	 * author of the row it boosts.
	 *
	 * A boost's own author is the booster, and either of the two can be muted
	 * — with the mute of one of them run out while the other still holds.
	 *
	 * @return string[]
	 */
	public static function authorColumns(string $alias, string $announceAlias): array {
		$columns = [$alias . '.attributed_to_prim'];
		if ($announceAlias !== '') {
			$columns[] = $announceAlias . '.attributed_to_prim';
		}

		return $columns;
	}

	/**
	 * The mutes of the viewer that have run out, joined against the authors of
	 * the rows being read.
	 *
	 * Joined *before* the relation anti-join of `filterHiddenActors()` and not
	 * after, because an expiry is a condition of that join and a join may only
	 * name an alias that already exists. Nothing is filtered here: this only
	 * puts the expiry within reach of `unexpired()` below.
	 *
	 * Costs an index probe per query for an account that has no timed mute, and
	 * nothing else.
	 *
	 * @param string $announceAlias the alias the boosted row is joined under; a
	 *                              boost's own author is the booster, and both
	 *                              can be muted. Empty to skip that half.
	 * @param int|null $now the moment the read happens, for a test that needs
	 *                      to place one either side of an expiry
	 */
	public static function joinExpiredMutes(
		SocialCoreQueryBuilder $qb, string $announceAlias = 'hd_o', ?int $now = null,
	): void {
		if (!$qb->hasViewer()) {
			return;
		}

		$expr = $qb->expr();
		$pf = $qb->getDefaultSelectAlias();

		$authors = [];
		foreach (self::authorColumns($pf, $announceAlias) as $author) {
			$authors[] = $expr->eq(self::ALIAS . '.object_id_prim', $author);
		}

		$expired = (new DateTime('@' . ($now ?? time())))
			->setTimezone(new DateTimeZone(date_default_timezone_get()));

		$qb->leftJoin(
			$pf, self::TABLE_MUTE_EXPIRY, self::ALIAS,
			$expr->andX(
				$expr->eq(
					self::ALIAS . '.actor_id_prim',
					$qb->createNamedParameter($qb->prim($qb->getViewer()->getId()))
				),
				$expr->orX(...$authors),
				$expr->lte(
					self::ALIAS . '.expires_at',
					$qb->createNamedParameter($expired, IQueryBuilder::PARAM_DATE)
				)
			)
		);
	}

	/**
	 * True of a mute row that still applies: the one whose expiry has passed is
	 * the row `joinExpiredMutes()` found, and it is that row and no other — the
	 * comparison is on the muted account, because a boost brings two authors
	 * into the same query and only one of them may have run out.
	 *
	 * The expiry is a predicate of the read and not a row that something
	 * deletes: a mute stops applying the second it expires, on an instance with
	 * no working cron as much as on one with.
	 */
	public static function unexpired(
		IExpressionBuilder $expr, string $relationAlias = 'hd_r', string $expiryAlias = self::ALIAS,
	): ICompositeExpression {
		return $expr->orX(
			$expr->isNull($expiryAlias . '.id'),
			$expr->neq($expiryAlias . '.object_id_prim', $relationAlias . '.object_id_prim')
		);
	}

	protected function getMuteExpiryInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_MUTE_EXPIRY);

		return $qb;
	}

	protected function getMuteExpiryUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_MUTE_EXPIRY);

		return $qb;
	}

	protected function getMuteExpirySelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('mx.id', 'mx.actor_id_prim', 'mx.object_id_prim', 'mx.expires_at', 'mx.creation')
			->from(self::TABLE_MUTE_EXPIRY, 'mx');

		$this->defaultSelectAlias = 'mx';
		$qb->setDefaultSelectAlias('mx');

		return $qb;
	}

	protected function getMuteExpiryDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_MUTE_EXPIRY);

		return $qb;
	}
}
