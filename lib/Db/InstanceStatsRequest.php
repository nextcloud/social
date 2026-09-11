<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Tools\Traits\TArrayTools;

/**
 * The two aggregates behind `stats` on `/api/v1/instance` and `usage` on
 * NodeInfo. Both walk a whole table, so `InstanceService` remembers their
 * result for a few minutes rather than asking on every hit — that endpoint is
 * the first request every client makes.
 */
class InstanceStatsRequest extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * Posts written on this instance: local Notes and Questions, whatever
	 * their visibility (Mastodon's `status_count` counts them all).
	 */
	public function countLocalStatuses(): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->from(self::TABLE_STREAM, 's');
		$qb->setDefaultSelectAlias('s');
		$qb->limitToLocal(true);
		$qb->limitToStatusTypes();

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * Distinct hosts among the remote actors this instance has cached.
	 *
	 * The host is the tail of `account` (`name@host`) and there is no column
	 * for it, so the distinct accounts are read and split here: SUBSTRING from
	 * a POSITION is not the same expression on every database this app runs
	 * on, and the caller caches the answer anyway.
	 */
	public function countRemoteDomains(): int {
		$qb = $this->getQueryBuilder();
		$qb->selectDistinct('ca.account')
			->from(self::TABLE_CACHE_ACTORS, 'ca');
		$qb->setDefaultSelectAlias('ca');
		$qb->limitToLocal(false);

		$hosts = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$account = (string)($data['account'] ?? '');
			$at = strrpos($account, '@');
			if ($at === false) {
				continue;
			}

			$host = strtolower(substr($account, $at + 1));
			if ($host !== '') {
				$hosts[$host] = true;
			}
		}
		$cursor->closeCursor();

		return count($hosts);
	}
}
