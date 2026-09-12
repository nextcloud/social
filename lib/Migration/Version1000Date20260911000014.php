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
 * The posts a client asked to have published later.
 *
 * One row per scheduled post. `actor_id_prim` is the md5 of the actor id, the
 * same key every other table here scopes an account by, and `actor_id` keeps
 * the URI itself because the cron has nothing else to resolve the poster from
 * — it runs with no session and no request, so it cannot start from a user id.
 *
 * `params` is the request the client sent, as JSON: Mastodon answers
 * `ScheduledStatus.params` with exactly that, and it is also everything the
 * job needs to build the post when the time comes. TEXT rather than a column
 * per field, because the set of fields is Mastodon's and grows with it —
 * `quoted_status_id` was not in it two releases ago — and because nothing ever
 * queries inside it. The one field that is *not* left as the client sent it is
 * the visibility: an absent one is resolved to the account's default when the
 * post is scheduled and stored resolved, so that changing the default
 * afterwards cannot widen the audience of a post that is already waiting.
 *
 * Two indexes, one per read there is:
 *
 *  - `social_sched_as` on `(actor_id_prim, scheduled_at)` — `GET
 *    /api/v1/scheduled_statuses` is one account's rows in scheduled order, and
 *    the daily cap counts one account's rows inside one day. Both are answered
 *    out of this index without touching the table.
 *  - `social_sched_due` on `(scheduled_at)` — the job asks the opposite
 *    question, which rows across *all* accounts are due, and the index above
 *    cannot answer it: its leading column is the account. Without this one
 *    every cron run is a full scan of the table.
 *
 * `id` is an autoincrement: it is the id the API hands a client, the path
 * segment the three single-row routes take, and the cursor the index route
 * pages on. It cannot be derived from anything else in the row — a client may
 * schedule two identical posts for the same minute.
 *
 * The columns are the ones `social_list` already ships on every supported
 * platform — a `BIGINT` autoincrement key, a `TEXT` actor id beside its
 * 32-character `STRING` digest, a `DATETIME`, and `TEXT` for the JSON — so the
 * DDL DBAL emits here is the DDL it emits there: `BIGINT UNSIGNED
 * AUTO_INCREMENT`/`LONGTEXT`/`VARCHAR`/`DATETIME` on MySQL and MariaDB,
 * `BIGSERIAL`/`TEXT`/`VARCHAR`/`TIMESTAMP(0) WITHOUT TIME ZONE` on PostgreSQL,
 * `NUMBER(20)`/`CLOB`/`VARCHAR2`/`TIMESTAMP(0)` with the sequence-and-trigger
 * pair on Oracle, and `INTEGER PRIMARY KEY AUTOINCREMENT` on SQLite. Both
 * index names are inside the 30 characters this app holds itself to and
 * carry the app prefix they need in PostgreSQL's database-wide index
 * namespace.
 *
 * `scheduled_at` is NOT NULL: a row with no time is one the job would never
 * pick up and the owner would never be able to publish, which is a post lost
 * with no way to tell.
 *
 * A new table on every platform, so nothing is rewritten and nothing is
 * locked: the cost of this step does not depend on the size of the instance. A
 * second run produces no statement anywhere — the step returns null before it
 * asks for anything.
 */
class Version1000Date20260911000014 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_scheduled')) {
			return null;
		}

		$table = $schema->createTable('social_scheduled');
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
		$table->addColumn('scheduled_at', Types::DATETIME, [
			'notnull' => true,
		]);
		$table->addColumn('params', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['actor_id_prim', 'scheduled_at'], 'social_sched_as');
		$table->addIndex(['scheduled_at'], 'social_sched_due');

		return $schema;
	}
}
