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
 * The hashtags an account follows.
 *
 * One row per (account, tag). `actor_id_prim` is the md5 of the actor id, the
 * same key every other table here joins actors by; `hashtag` is the tag with
 * no leading `#`, lowercased, and no wider than `social_stream_tag.hashtag` —
 * a tag that did not fit there could be followed and could never match a post.
 *
 * The unique index on the pair is both halves of the feature at once: it is
 * what makes following twice a no-op, and it is the index the home timeline
 * reads — that query asks for one account's tags and nothing else, so
 * `(actor_id_prim, hashtag)` answers it out of the index without touching the
 * table. No second index on `hashtag` alone: nothing asks who follows a tag.
 *
 * `id` is not the key anything looks a row up by; it is the cursor
 * `/api/v1/followed_tags` pages on, and an autoincrement column is the only
 * thing in the row that is stable and ordered (a tag can be unfollowed and
 * followed again, and `creation` is not unique).
 *
 * Verified against DBAL 3.10 as shipped in the server's 3rdparty:
 *
 *  - MySQL/MariaDB: one `CREATE TABLE … (id BIGINT UNSIGNED AUTO_INCREMENT
 *    NOT NULL, actor_id_prim VARCHAR(32) NOT NULL, hashtag VARCHAR(127) NOT
 *    NULL, creation DATETIME DEFAULT NULL, UNIQUE INDEX social_ft_ah
 *    (actor_id_prim, hashtag), PRIMARY KEY(id)) … ENGINE = InnoDB`. The
 *    unique index is 159 characters wide, well inside InnoDB's 3072-byte key
 *    limit even at four bytes a character — and it is the same pair of widths
 *    `social_stream_tag` has carried unique since 2022.
 *  - PostgreSQL: `CREATE TABLE` (`BIGSERIAL`, `VARCHAR`, `TIMESTAMP(0)
 *    WITHOUT TIME ZONE`) plus a separate `CREATE UNIQUE INDEX`.
 *  - Oracle: `CREATE TABLE` (`NUMBER(20)`, `VARCHAR2`, `TIMESTAMP(0)`), the
 *    PL/SQL block and sequence/trigger pair DBAL emits for an autoincrement
 *    column, then the `CREATE UNIQUE INDEX`.
 *  - SQLite: `CREATE TABLE` (`INTEGER PRIMARY KEY AUTOINCREMENT`) plus the
 *    `CREATE UNIQUE INDEX`.
 *
 * A new table on every platform, so nothing is rewritten and nothing is
 * locked: the cost of this step does not depend on the size of the instance.
 * A second run produces no statement anywhere — the step returns null before
 * it asks for anything.
 */
class Version1000Date20260911000004 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_followed_tag')) {
			return null;
		}

		$table = $schema->createTable('social_followed_tag');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('actor_id_prim', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('hashtag', Types::STRING, [
			'notnull' => true,
			'length' => 127,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['actor_id_prim', 'hashtag'], 'social_ft_ah');

		return $schema;
	}
}
