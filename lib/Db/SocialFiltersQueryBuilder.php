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
	 * @deprecated ?
	 */
	public function filterDuplicate() {
		if (!$this->hasViewer()) {
			return;
		}

		$viewer = $this->getViewer();
		$this->leftJoinFollowStatus('fs');

		$expr = $this->expr();

		$follower = $expr->andX(
			$this->exprLimitToDBField('attributed_to_prim', $this->prim($viewer->getId()), false)
		);

		$filter = $expr->orX(
			$this->exprLimitToDBFieldInt('filter_duplicate', 0, 's'),
			$follower
		);

		$this->andWhere($filter);
	}
}
