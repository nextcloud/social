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
 * Mastodon's lists: a user-made group of accounts they follow, with a timeline
 * of its own.
 *
 * Two tables, because the two things have different lifetimes and different
 * readers. `social_list` is the list itself — one row per (owner, list) — and
 * `social_list_member` is who is in it, one row per (list, account).
 *
 * `actor_id_prim` is the md5 of the actor id in both, the same key every other
 * table here joins actors by, and both carry the full `actor_id` alongside it
 * as `social_actor_relation` carries `object_id`: a prim is one-way, and
 * `GET /api/v1/lists/{id}/accounts` has to hand back accounts, which means it
 * needs the id the cache is keyed by and not only its hash. The owner's id is
 * kept for the same reason it is kept on a follow — so a row says who it
 * belongs to without a second lookup — while every predicate below is written
 * against the prim.
 *
 * `title` is VARCHAR(255), which is what Mastodon's own column is. It is not
 * unique per owner: Mastodon validates only that a title is present, and two
 * lists called "Friends" are two lists there.
 *
 * `replies_policy` is Mastodon's three-valued enum stored as its name
 * (`followed`, `list`, `none`) rather than as the ordinal Mastodon happens to
 * use, so a row means the same thing read outside this app. `exclusive` is the
 * 4.2 flag; both are stored and handed back, and neither yet changes which
 * posts a timeline selects — see docs/API.md.
 *
 * The indexes are the read paths, and there is one for each:
 *
 *  - `social_list_a` on `social_list(actor_id_prim)` — `GET /api/v1/lists` is
 *    "every list of one account", and every single-list route is
 *    `WHERE id = ? AND actor_id_prim = ?`: the primary key finds that row and
 *    the owner is a predicate on it, so a list is never read, changed or
 *    deleted by anybody but the account that owns it.
 *  - `social_lm_la` unique on `social_list_member(list_id, actor_id_prim)` —
 *    the members of a list, and the join the list timeline makes against
 *    `social_stream.attributed_to_prim`. Unique, so adding an account that is
 *    already in the list is a no-op rather than a second row, which is what
 *    makes `POST …/accounts` safe to retry.
 *  - `social_lm_a` on `social_list_member(actor_id_prim)` —
 *    `GET /api/v1/accounts/{id}/lists` asks the opposite question, which lists
 *    contain an account, and the unique index above cannot answer it: its
 *    leading column is the list. Unlike `social_followed_tag`, which has no
 *    such route and so has no such index, this one is a route a client calls
 *    on every profile it draws.
 *
 * `id` is an autoincrement in both. In `social_list` it is the id the API
 * hands a client and the path segment every route takes; in
 * `social_list_member` it is the cursor `GET …/accounts` pages on, since an
 * account can be removed from a list and added again and so does not move in
 * one direction.
 *
 * Verified against DBAL 3.10 as shipped in the server's 3rdparty:
 *
 *  - MySQL/MariaDB: two `CREATE TABLE … ENGINE = InnoDB`, with
 *    `BIGINT UNSIGNED AUTO_INCREMENT`, `VARCHAR`, `TINYINT(1)`, `LONGTEXT`
 *    and `DATETIME` columns and the indexes inline. `social_lm_la` is 32 + 20
 *    bytes of key, far inside InnoDB's 3072-byte limit even at four bytes a
 *    character.
 *  - PostgreSQL: `CREATE TABLE` (`BIGSERIAL`, `VARCHAR`, `BOOLEAN`, `TEXT`,
 *    `TIMESTAMP(0) WITHOUT TIME ZONE`) plus a `CREATE INDEX` per index.
 *  - Oracle: `CREATE TABLE` (`NUMBER(20)`, `VARCHAR2`, `NUMBER(1)`, `CLOB`,
 *    `TIMESTAMP(0)`), the PL/SQL block and sequence/trigger pair DBAL emits
 *    for each autoincrement column, then the `CREATE INDEX` statements.
 *  - SQLite: `CREATE TABLE` (`INTEGER PRIMARY KEY AUTOINCREMENT`) plus the
 *    `CREATE INDEX` statements.
 *
 * New tables on every platform, so nothing is rewritten and nothing is locked:
 * the cost of this step does not depend on the size of the instance. Each half
 * is guarded on its own, so an upgrade that failed between the two creates
 * only what is missing when it is run again, and a second successful run
 * produces no statement at all.
 */
class Version1000Date20260911000005 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;

		if (!$schema->hasTable('social_list')) {
			$table = $schema->createTable('social_list');
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
			$table->addColumn('title', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('replies_policy', Types::STRING, [
				'notnull' => true,
				'length' => 15,
				'default' => 'list',
			]);
			$table->addColumn('exclusive', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
			$table->addColumn('creation', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['actor_id_prim'], 'social_list_a');

			$changed = true;
		}

		if (!$schema->hasTable('social_list_member')) {
			$table = $schema->createTable('social_list_member');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			$table->addColumn('list_id', Types::BIGINT, [
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
			$table->addColumn('creation', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['list_id', 'actor_id_prim'], 'social_lm_la');
			$table->addIndex(['actor_id_prim'], 'social_lm_a');

			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
