<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

/**
 * Class SocialFiltersQueryBuilder
 *
 * @package OCA\Social\Db
 */
class SocialFiltersQueryBuilder extends SocialLimitsQueryBuilder {
	/**
	 * Keep the viewer's own boosts out of their home timeline.
	 *
	 * `filter_duplicate` is set on `Announce` (see `AP::getItemFromType()`), so
	 * the rule is: keep everything that is not a boost, plus every boost that
	 * is not the viewer's own.
	 *
	 * This used to be written as a call whose third argument looks like the
	 * `$cs` (case-sensitivity) flag but is in fact `$eq`. It happened to
	 * produce the right SQL; spelling the comparison out means the next reader
	 * does not have to work that out, and a `$eq`/`$cs` mix-up here would
	 * otherwise silently hide every boost by everybody the viewer follows.
	 */
	public function filterDuplicate() {
		if (!$this->hasViewer()) {
			return;
		}

		$expr = $this->expr();

		$this->andWhere(
			$expr->orX(
				$this->exprLimitToDBFieldInt('filter_duplicate', 0, 's'),
				$expr->neq(
					's.attributed_to_prim',
					$this->createNamedParameter($this->prim($this->getViewer()->getId()))
				)
			)
		);
	}
}
