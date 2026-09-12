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
 * The keyword filters an account mutes posts with.
 *
 * Two tables, because a filter has many keywords and Mastodon's v2 API
 * addresses each keyword by an id of its own (`/api/v2/filters/keywords/:id`):
 * a JSON list in one column could not be addressed, and could not be edited by
 * two clients at once without one of them losing the other's keyword.
 *
 * `social_filter` is one row per filter. `actor_id_prim` is the md5 of the
 * actor id, the same key every other table here joins actors by — a filter
 * belongs to exactly one account and is never anybody else's business, which
 * is why every read is scoped by it in SQL rather than checked afterwards.
 * `contexts` is the comma-joined list of the timelines the filter applies to
 * (`home`, `notifications`, `public`, `thread`, `account`): the list is read
 * whole, never searched by SQL, and stays legible in the row. `action` is
 * `warn` or `hide`. `expires_at` is nullable and NULL means "never": a filter
 * stops applying when its expiry passes because every read says so, not
 * because anything runs to clean it up — a cleanup job that failed would
 * otherwise keep hiding statuses the account expected back.
 *
 * `social_filter_kw` is one row per keyword, `filter_id` naming the filter it
 * belongs to. There is no foreign key: the rest of this schema has none
 * either, deletes are done in `FiltersRequest` inside the one request that
 * deletes the filter, and a constraint would make the two tables impossible to
 * migrate independently. `whole_word` is the flag that decides whether the
 * keyword is compared with word boundaries around it.
 *
 * One index per table, and each is the one its read path uses:
 *
 *  - `social_flt_actor` on (`actor_id_prim`). Every timeline read of a viewer
 *    with filters asks for that one account's filters and nothing else, and
 *    every route of the API asks the same way. Not unique: an account may have
 *    two filters with the same title, as it may on Mastodon. Not
 *    (`actor_id_prim`, `expires_at`) either — the expiry predicate removes a
 *    handful of rows from a list that is already a handful of rows, and the
 *    second column would only make the index wider for every write.
 *  - `social_fltkw_filter` on (`filter_id`), which is how a filter's keywords
 *    are read, and how they are deleted with it. Nothing asks which filters
 *    carry a given keyword, so there is no index on `keyword`.
 *
 * `id` is the cursor in both: it is the `id` the API hands a client, and the
 * only column in either row that is stable and unique.
 *
 * Verified against DBAL 3.10 as shipped in the server's 3rdparty:
 *
 *  - MySQL/MariaDB: two `CREATE TABLE … ENGINE = InnoDB` statements (`id`
 *    BIGINT UNSIGNED AUTO_INCREMENT, `VARCHAR`, `DATETIME DEFAULT NULL`,
 *    `SMALLINT`), each with its `INDEX` inline. The widest index is
 *    `actor_id_prim` alone at 32 characters, well inside InnoDB's 3072-byte
 *    key limit at four bytes a character.
 *  - PostgreSQL: `CREATE TABLE` (`BIGSERIAL`, `VARCHAR`, `TIMESTAMP(0)
 *    WITHOUT TIME ZONE`, `SMALLINT`) plus a separate `CREATE INDEX` each.
 *  - Oracle: `CREATE TABLE` (`NUMBER(20)`, `VARCHAR2`, `TIMESTAMP(0)`), the
 *    PL/SQL block and sequence/trigger pair DBAL emits for an autoincrement
 *    column, then the `CREATE INDEX`.
 *  - SQLite: `CREATE TABLE` (`INTEGER PRIMARY KEY AUTOINCREMENT`) plus the
 *    `CREATE INDEX`.
 *
 * New tables on every platform, so nothing is rewritten and nothing is locked:
 * the cost of this step does not depend on the size of the instance. A second
 * run produces no statement for a table that is already there, and the two are
 * checked separately so that a step interrupted between them completes on the
 * next run.
 */
class Version1000Date20260911000006 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;

		if (!$schema->hasTable('social_filter')) {
			$table = $schema->createTable('social_filter');
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
			$table->addColumn('title', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('contexts', Types::STRING, [
				'notnull' => true,
				'length' => 255,
				'default' => '',
			]);
			$table->addColumn('action', Types::STRING, [
				'notnull' => true,
				'length' => 15,
				'default' => 'warn',
			]);
			$table->addColumn('expires_at', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('creation', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['actor_id_prim'], 'social_flt_actor');

			$changed = true;
		}

		if (!$schema->hasTable('social_filter_kw')) {
			$table = $schema->createTable('social_filter_kw');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			$table->addColumn('filter_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			$table->addColumn('keyword', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('whole_word', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 1,
			]);
			$table->addColumn('creation', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['filter_id'], 'social_fltkw_filter');

			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
