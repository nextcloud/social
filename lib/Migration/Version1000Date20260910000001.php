<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The indexes the hot paths were missing.
 *
 * Every one of these is an index the code already queries as if it existed:
 *
 *  - `social_cache_doc.id_prim` is the join key for every avatar and every
 *    attachment — a timeline row joins it twice — and the table carried no
 *    index on it at all, only the `nid` primary key. The sibling table
 *    `social_cache_actor` has had `UNIQUE(id_prim)` from the start; this one
 *    gets a plain index rather than a unique one on purpose: nothing in the
 *    insert path has ever prevented two rows sharing a document id, so a
 *    unique index would abort the upgrade of any instance that has a
 *    duplicate. The index is what makes the join fast; the uniqueness would
 *    only add integrity, and needs a data-cleanup step first.
 *  - the favourites and bookmarks timelines filter `social_stream_act` by
 *    (actor, flag), the hashtag timeline filters `social_stream_tag` by tag,
 *    and the like/boost counters group `social_action` by (object, type).
 *  - both queues drain by `status` ordered by `id`, and the request queue also
 *    reports on `tries`.
 *  - `social_client.token` was reachable only through a unique index that
 *    leads on `auth_code`, so every single API request that authenticates with
 *    a bearer token scanned the whole table.
 *  - retention prunes `social_stream` ordered by `creation` and deletes
 *    attachments by `social_cache_doc.parent_id_prim`; the remote-actor cron
 *    selects `social_cache_actor` by (local, details_update).
 *  - `social_follow` is looked up by the (object, actor) pair without the
 *    `accepted` flag — by `getByPersons()` and by `accepted()` itself — which
 *    neither of the two existing unique indexes can serve, because both of
 *    them lead with `accepted`. (Which is also why they do not make the pair
 *    unique: an accepted and a pending row for the same pair can coexist.
 *    Making it unique needs a dedup pass and is left to a separate change.)
 *
 * Also drops `ipoha`: a five-column unique index on `social_stream` whose
 * uniqueness is already implied by its own first column, which is separately
 * declared unique. It costs an index write on every insert into the largest
 * table on the instance and can serve no query the `id_prim` index cannot.
 *
 * What this costs an operator depends entirely on the database:
 *
 *  - MySQL/MariaDB and PostgreSQL get what it says on the tin — 15 statements,
 *    index additions plus the one drop, no table touched otherwise. On
 *    MySQL/MariaDB the additions are online (ALGORITHM=INPLACE) but still take
 *    time proportional to the table, and `social_stream` / `social_stream_act`
 *    / `social_cache_doc` are the big ones.
 *  - SQLite cannot add or drop an index in place: Doctrine's SqlitePlatform
 *    implements every index change as a full table rebuild, so these fourteen
 *    additions and one drop become 79 statements that copy ten tables —
 *    `social_stream`, `social_cache_actor`, `social_cache_doc`,
 *    `social_follow`, `social_action`, `social_client`, both queues and both
 *    stream side tables — into a temporary table, drop the original, recreate
 *    it and copy the rows back, then rebuild every index each table had.
 *    Plan the maintenance window for a rewrite of nearly the whole app schema,
 *    and for peak disk of roughly twice what those tables occupy. The data is
 *    not at risk: SQLite DDL is transactional and Nextcloud runs a migration
 *    inside a transaction on every platform except MySQL.
 */
class Version1000Date20260910000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		// [table, columns, index name] — names stay under the 30-character limit
		foreach ([
			['social_cache_doc', ['id_prim'], 'social_cd_idp'],
			['social_cache_doc', ['parent_id_prim'], 'social_cd_pidp'],
			['social_stream_act', ['actor_id_prim', 'liked'], 'social_sa_al'],
			['social_stream_act', ['actor_id_prim', 'bookmarked'], 'social_sa_ab'],
			['social_stream_tag', ['hashtag'], 'social_st_ht'],
			['social_action', ['object_id_prim', 'type'], 'social_a_oit'],
			['social_req_queue', ['status', 'id'], 'social_rq_si'],
			['social_req_queue', ['tries'], 'social_rq_tries'],
			['social_stream_queue', ['status', 'id'], 'social_sq_si'],
			['social_client', ['token'], 'social_cl_tok'],
			['social_stream', ['creation'], 'social_s_crea'],
			['social_cache_actor', ['local', 'details_update'], 'social_ca_ldu'],
			['social_follow', ['object_id_prim', 'actor_id_prim'], 'social_f_oa'],
			['social_follow', ['object_id_prim', 'creation'], 'social_f_ocr'],
		] as [$tableName, $columns, $indexName]) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}

			$table = $schema->getTable($tableName);
			foreach ($columns as $column) {
				if (!$table->hasColumn($column)) {
					continue 2;
				}
			}

			if ($table->hasIndex($indexName)) {
				continue;
			}

			$table->addIndex($columns, $indexName);
			$changed = true;
		}

		if ($schema->hasTable('social_stream')) {
			$table = $schema->getTable('social_stream');
			if ($table->hasIndex('ipoha')) {
				$table->dropIndex('ipoha');
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
