<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\HeldPost;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * The four statements the review queue is made of.
 */
class PostHoldsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	protected function getPostHoldInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_POST_HOLD);

		return $qb;
	}

	protected function getPostHoldSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('ph.id', 'ph.actor_id', 'ph.actor_id_prim', 'ph.params', 'ph.reason', 'ph.digest', 'ph.creation')
			->from(self::TABLE_POST_HOLD, 'ph');

		$this->defaultSelectAlias = 'ph';
		$qb->setDefaultSelectAlias('ph');

		return $qb;
	}

	protected function getPostHoldDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_POST_HOLD);

		return $qb;
	}

	protected function parsePostHoldSelectSql(array $data): HeldPost {
		return (new HeldPost())->importFromDatabase($data);
	}
}
