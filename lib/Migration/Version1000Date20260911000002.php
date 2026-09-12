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
 * Directory flags and migration state for local actors.
 *
 *  - `discoverable` / `indexable`: whether the account may be listed in
 *    directories and suggestions, and whether its posts may be full-text
 *    indexed by other servers. Mastodon defaults both to false for an actor
 *    that says nothing, so an actor here that never emitted them never
 *    appeared anywhere. Opt-in like there: existing actors stay at 0.
 *  - `also_known_as`: the JSON list of actor ids this one also answers to. A
 *    remote server accepts a Move *into* here only when the actor here lists
 *    the moving account, so it has to be settable before the move.
 *  - `moved_to`: the actor this one moved to, once it has. Emitted as
 *    `movedTo` on the actor document and as `moved` on the account entity.
 *
 * The two id columns are TEXT: an actor id is a URL of no bounded length (the
 * `id` column of the same table is TEXT for the same reason), and a TEXT
 * column may not carry a default on MySQL, so they are nullable and unset
 * reads as empty.
 *
 * Verified against DBAL 3.10 as shipped in the server's 3rdparty:
 *
 *  - MySQL/MariaDB: one `ALTER TABLE … ADD discoverable SMALLINT DEFAULT 0
 *    NOT NULL, ADD indexable SMALLINT DEFAULT 0 NOT NULL, ADD also_known_as
 *    LONGTEXT DEFAULT NULL, ADD moved_to LONGTEXT DEFAULT NULL`; in place, no
 *    table copy.
 *  - PostgreSQL: four `ALTER TABLE … ADD <column> …` statements, one per
 *    column (`SMALLINT DEFAULT 0 NOT NULL` twice, `TEXT DEFAULT NULL` twice).
 *  - Oracle: one `ALTER TABLE … ADD (discoverable NUMBER(5) DEFAULT 0 NOT
 *    NULL, indexable NUMBER(5) DEFAULT 0 NOT NULL, also_known_as CLOB DEFAULT
 *    NULL, moved_to CLOB DEFAULT NULL)`.
 *  - SQLite: four plain `ALTER TABLE … ADD COLUMN` statements (`CLOB` for the
 *    two TEXT columns) — adding a column with a constant default is one of the
 *    changes SQLite can do in place, so no table rebuild.
 *
 * A second run on the changed schema produces no statement on any of them.
 */
class Version1000Date20260911000002 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_actor')) {
			return $schema;
		}

		$table = $schema->getTable('social_actor');

		foreach (['discoverable', 'indexable'] as $flag) {
			if (!$table->hasColumn($flag)) {
				$table->addColumn($flag, Types::SMALLINT, [
					'notnull' => true,
					'default' => 0,
					'length' => 1,
				]);
			}
		}

		foreach (['also_known_as', 'moved_to'] as $column) {
			if (!$table->hasColumn($column)) {
				$table->addColumn($column, Types::TEXT, [
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}
}
