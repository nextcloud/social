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
 * The record a moderation decision leaves behind: `social_strike`.
 *
 * `social_moderation` holds what stands against an account *now* — one row per
 * account, replaced by the next decision and deleted when it is lifted. So the
 * ladder went from nothing straight to a silence with no step in between, and
 * the third silence in a month looked exactly like the first: whoever lifted
 * the last one took the only evidence that it had ever happened with it.
 *
 * A strike is the history. One row per decision, never updated, never removed
 * by a lift: what was decided, why, by whom, and which report prompted it. A
 * warning — Mastodon's `none` — is a strike that applied nothing, which is the
 * step the ladder was missing.
 */
class Version1000Date20260912000003 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_strike')) {
			return null;
		}

		$table = $schema->createTable('social_strike');
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
		$table->addColumn('actor_id', Types::STRING, [
			'notnull' => true,
			'length' => 1000,
		]);
		/** 'none' is a warning, which applied nothing; otherwise a Moderation level */
		$table->addColumn('action', Types::STRING, [
			'notnull' => true,
			'length' => 15,
		]);
		/** what the moderator wrote, and what the account is shown */
		$table->addColumn('text', Types::TEXT, [
			'notnull' => false,
		]);
		/** the Nextcloud user who took it, so a history names somebody */
		$table->addColumn('moderator', Types::STRING, [
			'notnull' => false,
			'length' => 64,
		]);
		/** the report it came from, or 0 */
		$table->addColumn('report_id', Types::INTEGER, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		// the history of one account, newest first, is the only read there is
		$table->addIndex(['actor_id_prim'], 'social_str_aid');

		return $schema;
	}
}
