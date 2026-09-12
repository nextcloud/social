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
 * The versions a status has been through.
 *
 * Editing a post has always worked and `edited_at` has always been real, but
 * nothing kept the text that was replaced: `PostService::snapshotSource()`
 * overwrites the stored wire object with the current version, so the only copy
 * of the previous one was in whatever cache had not caught up yet. There was
 * therefore nothing `GET /api/v1/statuses/:id/history` could have answered.
 *
 * One row per version, oldest first, including the original — an edit writes
 * the version it replaces as well as the new one when it is the first edit, so
 * the first row of a status is always the text that was posted and never the
 * text that is showing now.
 *
 * `stream_id_prim` is the md5 of the status id, the same key every other side
 * table of `social_stream` hangs off (`social_stream_act`, `social_stream_card`),
 * and not `nid`: `nid` is the client-facing cursor and is assigned by the
 * stream table, while the prim is what the delete paths already carry.
 *
 * `content` and `spoiler_text` mirror `social_stream.content` and `.summary` —
 * TEXT, because a revision that could not hold what the status held would
 * silently truncate somebody's post the moment it was edited. `published` is
 * the ISO stamp that version carries (`published` for the original, the edit's
 * `updated` for every later one) and is the `created_at` of Mastodon's
 * StatusEdit entity; it is VARCHAR(31) like `social_stream.published`, stored
 * as the string that federated rather than reparsed into a date, so the value
 * a remote server saw and the value a client is told are the same characters.
 *
 * The index is `(stream_id_prim, id)` and it is the only read there is: one
 * status, every version, in the order they were written. `id` is in it because
 * the order is the answer — two edits inside the same second are ordered by
 * nothing else — so the index alone serves the query without touching the
 * table. No index on `id` alone beyond the primary key: nothing asks for a
 * revision without knowing whose it is.
 *
 * Verified against DBAL 3.10 as shipped in the server's 3rdparty:
 *
 *  - MySQL/MariaDB: one `CREATE TABLE … (id BIGINT UNSIGNED AUTO_INCREMENT
 *    NOT NULL, stream_id_prim VARCHAR(32) NOT NULL, content LONGTEXT DEFAULT
 *    NULL, spoiler_text LONGTEXT DEFAULT NULL, sensitive SMALLINT DEFAULT 0
 *    NOT NULL, published VARCHAR(31) DEFAULT '', creation DATETIME DEFAULT
 *    NULL, INDEX social_sr_si (stream_id_prim, id), PRIMARY KEY(id)) …
 *    ENGINE = InnoDB`. The index is 32 characters and a bigint wide, far
 *    inside InnoDB's 3072-byte key limit, and it is not unique — a status may
 *    be edited to the same text twice and both edits are versions.
 *  - PostgreSQL: `CREATE TABLE` (`BIGSERIAL`, `VARCHAR`, `TEXT`, `SMALLINT`,
 *    `TIMESTAMP(0) WITHOUT TIME ZONE`) plus a separate `CREATE INDEX`.
 *  - Oracle: `CREATE TABLE` (`NUMBER(20)`, `VARCHAR2`, `CLOB`, `NUMBER(5)`,
 *    `TIMESTAMP(0)`), the PL/SQL block and sequence/trigger pair DBAL emits
 *    for an autoincrement column, then the `CREATE INDEX`.
 *  - SQLite: `CREATE TABLE` (`INTEGER PRIMARY KEY AUTOINCREMENT`, `CLOB`)
 *    plus the `CREATE INDEX`.
 *
 * A new table on every platform, so nothing is rewritten and nothing is
 * locked: the cost of this step does not depend on the size of the instance.
 * A second run produces no statement anywhere — the step returns null before
 * it asks for anything.
 *
 * Nothing is backfilled and nothing can be. A status edited before this table
 * existed has one version left in the database, and inventing an "original"
 * row out of the current text would publish a revision history that says the
 * post was never changed — see StatusRevisionsRequest, which answers such a
 * status with the single version it can honestly account for.
 */
class Version1000Date20260911000009 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_stream_rev')) {
			return null;
		}

		$table = $schema->createTable('social_stream_rev');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('stream_id_prim', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('content', Types::TEXT, [
			'notnull' => false,
			'default' => '',
		]);
		$table->addColumn('spoiler_text', Types::TEXT, [
			'notnull' => false,
			'default' => '',
		]);
		$table->addColumn('sensitive', Types::SMALLINT, [
			'notnull' => true,
			'default' => 0,
			'length' => 1,
		]);
		$table->addColumn('published', Types::STRING, [
			'notnull' => false,
			'length' => 31,
			'default' => '',
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['stream_id_prim', 'id'], 'social_sr_si');

		return $schema;
	}
}
