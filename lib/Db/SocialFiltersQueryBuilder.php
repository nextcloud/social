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
		$filter = $expr->orX();
		$filter->add($this->exprLimitToDBFieldInt('filter_duplicate', 0, 's'));

		$follower = $expr->andX();
		$follower->add($this->exprLimitToDBField('attributed_to_prim', $this->prim($viewer->getId()), false));
		//		$follower->add($expr->isNull('fs.id_prim'));
		$filter->add($follower);

		$this->andWhere($filter);
	}
}
