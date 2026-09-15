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
 * The individual posts a filter covers, beside the keywords it matches.
 *
 * Mastodon's v2 filter API has two ways of saying what a filter catches: a
 * keyword, which matches whatever it matches from now on, and a *status* — one
 * named post, filtered because of what it is rather than what it says. A
 * client offers the second as "filter this post", and the routes for it
 * (`/api/v2/filters/{id}/statuses` and `/api/v2/filters/statuses/{id}`) were
 * the only part of the v2 API this app did not serve.
 *
 * One row per (filter, post). `filter_id` names the filter, as in
 * `social_filter_kw`, and for the same reasons: no foreign key, because
 * nothing in this schema has one and the deletes are done together in
 * `FiltersRequest`. `status_id` is the post's `nid` — the same number the
 * client API hands out as a status id and sends back here — rather than the
 * ActivityPub URI: a client names a post by that id, and storing the URI would
 * mean a lookup on every write and a second one on every read to compare them.
 *
 * A post that is deleted leaves its row behind. That is deliberate: `nid` is
 * never reused, so the row can never come to mean a different post, and a
 * cascade would mean either a foreign key this schema does not use or a sweep
 * that would have to run over every filter of every account to collect a
 * handful of rows nothing reads.
 *
 * Two indexes, each the one a read path uses:
 *
 *  - `social_fltst_filter` on (`filter_id`), which is how a filter's statuses
 *    are listed and how they are deleted with it.
 *  - `social_fltst_status` on (`status_id`). Not unique, and unique would be
 *    wrong: two filters of the same account may each cover the same post, and
 *    two accounts certainly may.
 *
 * `id` is the cursor and the id the API hands a client, exactly as in the
 * keyword table: a FilterStatus is addressed by its own id, not by the post's.
 *
 * Verified against DBAL 3.10 as shipped in the server's 3rdparty:
 *
 *  - MySQL/MariaDB: one `CREATE TABLE … ENGINE = InnoDB` (`id` and the two
 *    `BIGINT UNSIGNED` columns, `id` AUTO_INCREMENT) with both `INDEX`
 *    clauses inline. Eight bytes an index, nowhere near InnoDB's key limit.
 *  - PostgreSQL: `CREATE TABLE` (`BIGSERIAL`, `BIGINT`) plus a `CREATE INDEX`
 *    each.
 *  - Oracle: `CREATE TABLE` (`NUMBER(20)`), the PL/SQL block and
 *    sequence/trigger pair DBAL emits for an autoincrement column, then the
 *    two `CREATE INDEX` statements.
 *  - SQLite: `CREATE TABLE` (`INTEGER PRIMARY KEY AUTOINCREMENT`) plus the two
 *    `CREATE INDEX` statements.
 *
 * A new table on every platform, so nothing is rewritten and nothing is
 * locked: the cost does not depend on the size of the instance. A second run
 * produces no statement at all.
 */
class Version1000Date20260915000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_filter_st')) {
			return null;
		}

		$table = $schema->createTable('social_filter_st');
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
		$table->addColumn('status_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['filter_id'], 'social_fltst_filter');
		$table->addIndex(['status_id'], 'social_fltst_status');

		return $schema;
	}
}
