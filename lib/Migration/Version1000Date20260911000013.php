<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Who is dealing with a report, and who acted on it.
 *
 * `social_report` recorded that a report was resolved and nothing about the
 * moderator who resolved it, which is the whole of Mastodon's
 * `Admin::Report.assigned_account` and `action_taken_by_account` — and without
 * somewhere to put them, `POST /api/v1/admin/reports/{id}/assign_to_self` and
 * `/unassign` could only have answered as though they had done something. On
 * an instance with more than one administrator that is the difference between
 * a queue and two people working the same report.
 *
 *  - `assigned_to`: the Nextcloud user id of the moderator who took the
 *    report, empty while nobody has. Not an actor id: the moderator acts as an
 *    administrator of this server, and an administrator need not have a Social
 *    account at all.
 *  - `action_taken_by`: the same, for whoever resolved it.
 *  - `action_taken_at`: when they did. Left null on a report resolved before
 *    this step ran, which is why `Admin::Report.action_taken_at` is nullable —
 *    those reports are resolved and the moment is not recorded.
 *
 * The two user-id columns are VARCHAR(64), the width Nextcloud bounds a user
 * id at and the width `social_client.auth_user_id` already uses. Both are
 * nullable rather than defaulted to '': a report is assigned to nobody far
 * more often than to somebody, and a NULL is what every reader here reads as
 * "nobody" anyway.
 *
 * No index. The columns are read with the report row a moderator already
 * asked for, never searched on: nothing lists "reports assigned to me" — the
 * whole table is a few hundred rows on the largest instance this app runs on,
 * and the list route already reads it in id order.
 *
 * Verified against DBAL 3.10 as shipped in the server's 3rdparty:
 *
 *  - MySQL/MariaDB: one `ALTER TABLE social_report ADD assigned_to
 *    VARCHAR(64) DEFAULT NULL, ADD action_taken_by VARCHAR(64) DEFAULT NULL,
 *    ADD action_taken_at DATETIME DEFAULT NULL`; in place, no table copy.
 *  - PostgreSQL: three `ALTER TABLE … ADD <column> …` statements (`VARCHAR(64)
 *    DEFAULT NULL` twice, `TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL`).
 *  - Oracle: one `ALTER TABLE … ADD (assigned_to VARCHAR2(64) DEFAULT NULL,
 *    action_taken_by VARCHAR2(64) DEFAULT NULL, action_taken_at TIMESTAMP(0)
 *    DEFAULT NULL)`.
 *  - SQLite: three plain `ALTER TABLE … ADD COLUMN` statements — a nullable
 *    column with a constant default is one of the changes SQLite makes in
 *    place, so no table rebuild.
 *
 * A second run produces no statement anywhere: each column is asked for only
 * when the table does not already have it.
 */
class Version1000Date20260911000013 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_report')) {
			return $schema;
		}

		$table = $schema->getTable('social_report');

		foreach (['assigned_to', 'action_taken_by'] as $column) {
			if (!$table->hasColumn($column)) {
				$table->addColumn($column, Types::STRING, [
					'notnull' => false,
					'length' => 64,
				]);
			}
		}

		if (!$table->hasColumn('action_taken_at')) {
			$table->addColumn('action_taken_at', Types::DATETIME, [
				'notnull' => false,
			]);
		}

		return $schema;
	}
}
