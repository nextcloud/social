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
 * The three things one account keeps about another that `social_actor_relation`
 * cannot hold: a block of a whole instance, a private note, and the moment a
 * mute stops applying.
 *
 * `social_actor_relation` holds the fourth of this group — an endorsement is a
 * row there with type `endorse`, because it is exactly what that table is: one
 * actor, one other actor, one word for the relation, no payload. The three
 * tables below exist because each of them is *not* that shape.
 *
 * `social_domain_block` is a block of every account on one instance, held by
 * one local account. It is not actor-to-actor at all: the account it blocks
 * may not exist yet, which is the whole point of blocking the instance rather
 * than the accounts on it. `actor_id_prim` is the md5 of the blocker's actor
 * id, the key every other table here joins actors by, and `domain` is the host
 * as it appears in an actor id — lowercased, no port, no scheme, no leading
 * `@`, and no wildcard character can reach it (`DomainBlockService::
 * normalise()` refuses anything outside `a-z0-9.-`), which is what makes it
 * safe to compare with LIKE in the timeline join. The unique index on
 * (`actor_id_prim`, `domain`) is both halves of the feature: blocking twice is
 * a no-op, and it is the index every timeline read probes — one probe per
 * query, and no rows at all for the accounts that block no instance.
 *
 * `social_account_note` is a memo one account keeps about another, so unlike a
 * relation it carries a payload and there is exactly one per pair — the unique
 * index on (`actor_id_prim`, `object_id_prim`) is what makes writing a second
 * note an update of the first. `object_id` is kept beside its md5 because the
 * prim is one-way: without it the row cannot say who the note is about, and
 * this is the one table here whose rows are worth exporting with an account.
 * `note` is TEXT: Mastodon caps a note at 2000 characters, which no VARCHAR
 * index could cover and nothing ever searches by.
 *
 * `social_mute_expiry` is the expiry of a mute, one row per (muter, muted),
 * and only for a mute that has one — a permanent mute, which is the common
 * case, stores nothing at all. It is a side table rather than a column on
 * `social_actor_relation` because that table holds three kinds of row
 * (`block`, `blocked_by`, `mute`) and only one of them can expire: a column
 * there would be NULL on every block row and every writer of that table would
 * have to know about it. `expires_at` is NOT NULL — a row with no expiry is a
 * row that should not exist — and the mute stops applying because every read
 * says so, not because anything runs to delete it: an instance with no working
 * cron behaves like one that has. `object_id` is not repeated here; this row is
 * an attribute of a `social_actor_relation` row, which carries the id already.
 *
 * Verified against DBAL 3.10 as shipped in the server's 3rdparty:
 *
 *  - MySQL/MariaDB: three `CREATE TABLE … ENGINE = InnoDB` statements (`id`
 *    BIGINT UNSIGNED AUTO_INCREMENT, `VARCHAR`, `LONGTEXT`, `DATETIME`), each
 *    with its `UNIQUE INDEX` inline. The widest is (`actor_id_prim`, `domain`)
 *    at 287 characters — 1148 bytes at four bytes a character, well inside
 *    InnoDB's 3072-byte key limit.
 *  - PostgreSQL: `CREATE TABLE` (`BIGSERIAL`, `VARCHAR`, `TEXT`,
 *    `TIMESTAMP(0) WITHOUT TIME ZONE`) plus a separate `CREATE UNIQUE INDEX`
 *    each.
 *  - Oracle: `CREATE TABLE` (`NUMBER(20)`, `VARCHAR2`, `CLOB`, `TIMESTAMP(0)`),
 *    the PL/SQL block and sequence/trigger pair DBAL emits for an
 *    autoincrement column, then the `CREATE UNIQUE INDEX`.
 *  - SQLite: `CREATE TABLE` (`INTEGER PRIMARY KEY AUTOINCREMENT`) plus the
 *    `CREATE UNIQUE INDEX`.
 *
 * New tables on every platform, so nothing is rewritten and nothing is locked:
 * the cost of this step does not depend on the size of the instance. Each
 * table is checked for separately, so a step interrupted between two of them
 * completes on the next run, and a second run produces no statement at all.
 */
class Version1000Date20260911000008 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;

		if (!$schema->hasTable('social_domain_block')) {
			$table = $schema->createTable('social_domain_block');
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
			$table->addColumn('domain', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('creation', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim', 'domain'], 'social_dblk_ad');

			$changed = true;
		}

		if (!$schema->hasTable('social_account_note')) {
			$table = $schema->createTable('social_account_note');
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
			$table->addColumn('object_id', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('object_id_prim', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('note', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('creation', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim', 'object_id_prim'], 'social_anote_ao');

			$changed = true;
		}

		if (!$schema->hasTable('social_mute_expiry')) {
			$table = $schema->createTable('social_mute_expiry');
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
			$table->addColumn('object_id_prim', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('expires_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('creation', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim', 'object_id_prim'], 'social_mexp_ao');

			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
