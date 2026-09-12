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
 * What one account has done with one conversation.
 *
 * The conversations themselves get no table: a conversation here is a thread
 * of `social_stream` rows, derived from `in_reply_to` at read time, and its id
 * is the nid of the thread's root — see lib/Service/ConversationService.php
 * for what that buys and what it costs. Read and dismissed state is the part
 * that cannot be derived from the messages, so it is the only part stored.
 *
 * One row per (account, thread), written the first time either marker moves.
 * An account that has never opened its conversations has no rows here at all,
 * which is also what an instance upgrading into this table starts with:
 * nothing to backfill, because "never read" is the default the reader applies
 * to a thread with no row.
 *
 * Both markers are the nid of the newest message the action covered, not a
 * flag:
 *
 *  - `read_nid` — a message arriving afterwards has a higher nid, so it is
 *    outside the marker and the conversation is unread again, as on Mastodon.
 *  - `hidden_nid` — `DELETE /api/v1/conversations/{id}` dismisses what is
 *    there now; a later message brings the conversation back, which is what
 *    Mastodon does by re-creating the conversation row it deleted.
 *
 * A flag would need clearing on every incoming direct message, i.e. a write to
 * this table on the delivery path, and would lose the distinction between "the
 * user read this thread" and "the user read this thread up to here".
 *
 * `actor_id_prim` and `root_id_prim` are the md5s every other table here joins
 * ids by, and both full ids are kept beside them as `social_list` keeps
 * `actor_id`: a prim is one-way, and the writer needs the root's real id to
 * store the row at all.
 *
 * One index, and it is the only read path: `social_convo_ar` unique on
 * `(actor_id_prim, root_id_prim)`. Unique, because two rows for one account
 * and one thread would mean one of them silently deciding what the user has
 * read; that uniqueness is also what makes the marker write safe to retry —
 * the writer inserts, and an insert that loses the race becomes an update.
 * Its leading column answers "every state of one account", which is what an
 * account being deleted needs, so there is no second index for it.
 *
 * Verified against DBAL 3.10 as shipped in the server's 3rdparty:
 *
 *  - MySQL/MariaDB: one `CREATE TABLE … ENGINE = InnoDB` with
 *    `BIGINT UNSIGNED AUTO_INCREMENT`, `LONGTEXT`, `VARCHAR(32)`,
 *    `BIGINT UNSIGNED` and `DATETIME` columns and the unique index inline. The
 *    index is 32 + 32 characters of key, far inside InnoDB's 3072-byte limit
 *    even at four bytes a character.
 *  - PostgreSQL: `CREATE TABLE` (`BIGSERIAL`, `TEXT`, `VARCHAR(32)`, `BIGINT`,
 *    `TIMESTAMP(0) WITHOUT TIME ZONE`) plus one `CREATE UNIQUE INDEX`.
 *  - Oracle: `CREATE TABLE` (`NUMBER(20)`, `CLOB`, `VARCHAR2(32)`,
 *    `TIMESTAMP(0)`), the PL/SQL block and sequence/trigger pair DBAL emits
 *    for the autoincrement column, then the `CREATE UNIQUE INDEX`.
 *  - SQLite: `CREATE TABLE` (`INTEGER PRIMARY KEY AUTOINCREMENT`) plus the
 *    `CREATE UNIQUE INDEX`.
 *
 * A new table on every platform, so nothing is rewritten and nothing is
 * locked: the cost of this step does not depend on the size of the instance.
 * A second run produces no statement at all.
 */
class Version1000Date20260911000007 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_convo_state')) {
			return null;
		}

		$table = $schema->createTable('social_convo_state');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('actor_id', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('actor_id_prim', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('root_id', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('root_id_prim', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('read_nid', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('hidden_nid', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['actor_id_prim', 'root_id_prim'], 'social_convo_ar');

		return $schema;
	}
}
