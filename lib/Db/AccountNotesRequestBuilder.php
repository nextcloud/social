<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class AccountNotesRequestBuilder
 *
 * @package OCA\Social\Db
 */
class AccountNotesRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	protected function getAccountNotesInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ACCOUNT_NOTES);

		return $qb;
	}

	protected function getAccountNotesUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ACCOUNT_NOTES);

		return $qb;
	}

	protected function getAccountNotesSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('an.id', 'an.actor_id_prim', 'an.object_id', 'an.object_id_prim', 'an.note', 'an.creation')
			->from(self::TABLE_ACCOUNT_NOTES, 'an');

		$this->defaultSelectAlias = 'an';
		$qb->setDefaultSelectAlias('an');

		return $qb;
	}

	protected function getAccountNotesDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ACCOUNT_NOTES);

		return $qb;
	}
}
