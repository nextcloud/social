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
 * The instance's announcements, and who has dismissed which.
 *
 * `social_announcement` is one row per notice an admin posted. `content` holds
 * the text as it was typed — the entity's HTML is built from it when a client
 * is answered, so what is stored can be shown back in the admin form as the
 * admin wrote it. `starts_at`/`ends_at` are the window the announcement is
 * served in and are NULL when it has none; both are compared in the query that
 * reads them (`AnnouncementsRequest::getActive()`), so nothing has to run for
 * an announcement to start or stop applying. `all_day` says the window is
 * whole days rather than moments, which is what Mastodon's flag of that name
 * means. `creation` is the entity's `published_at` and `last_update` its
 * `updated_at`, which Mastodon documents as non-nullable.
 *
 * No index on either date: every read of this table is the whole active set of
 * a table that holds a handful of rows, and an index would be read past on all
 * of them. The primary key is what the admin routes name a row by.
 *
 * `social_announce_read` is one row per (account, announcement), the account
 * as `actor_id_prim` — the md5 of the actor id, the key every other table here
 * joins actors by. The unique index on the pair is both halves of the feature
 * at once: it is what makes dismissing twice a no-op, and it is the index the
 * client read probes — that query asks for one account's dismissals among the
 * ids of one page, so `(actor_id_prim, announcement_id)` answers it out of the
 * index. It is that way round and not the other for the same reason: the
 * account is the constant. Deleting an announcement sweeps its dismissals
 * without an index, which is one statement on a table nobody deletes from
 * twice a year.
 *
 * Verified against DBAL 3.10 as shipped in the server's 3rdparty:
 *
 *  - MySQL/MariaDB: two `CREATE TABLE … ENGINE = InnoDB` statements (`id`
 *    BIGINT UNSIGNED AUTO_INCREMENT, `LONGTEXT`, `DATETIME DEFAULT NULL`,
 *    `SMALLINT`, `VARCHAR(32)`), the unique index inline. That index is 32
 *    characters plus a BIGINT, well inside InnoDB's 3072-byte key limit even
 *    at four bytes a character.
 *  - PostgreSQL: `CREATE TABLE` (`BIGSERIAL`, `TEXT`, `TIMESTAMP(0) WITHOUT
 *    TIME ZONE`, `SMALLINT`, `VARCHAR`) plus a separate `CREATE UNIQUE INDEX`.
 *  - Oracle: `CREATE TABLE` (`NUMBER(20)`, `CLOB`, `TIMESTAMP(0)`,
 *    `VARCHAR2`), the PL/SQL block and sequence/trigger pair DBAL emits for an
 *    autoincrement column, then the `CREATE UNIQUE INDEX`.
 *  - SQLite: `CREATE TABLE` (`INTEGER PRIMARY KEY AUTOINCREMENT`, `CLOB`)
 *    plus the `CREATE UNIQUE INDEX`.
 *
 * New tables on every platform, so nothing is rewritten and nothing is locked:
 * the cost of this step does not depend on the size of the instance. A second
 * run produces no statement for a table that is already there, and the two are
 * checked separately so that a step interrupted between them completes on the
 * next run.
 */
class Version1000Date20260911000011 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;

		if (!$schema->hasTable('social_announcement')) {
			$table = $schema->createTable('social_announcement');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			$table->addColumn('content', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('starts_at', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('ends_at', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('all_day', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 1,
			]);
			$table->addColumn('creation', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('last_update', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);

			$changed = true;
		}

		if (!$schema->hasTable('social_announce_read')) {
			$table = $schema->createTable('social_announce_read');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			$table->addColumn('announcement_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			$table->addColumn('actor_id_prim', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('creation', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim', 'announcement_id'], 'social_annread_aa');

			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
