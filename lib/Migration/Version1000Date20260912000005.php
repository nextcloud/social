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
 * Reactions to an announcement: `social_announce_react`.
 *
 * `Announcement.reactions` was always `[]` and the two routes that write one
 * did not exist, so the only thing an account could do with an instance-wide
 * notice was dismiss it — and an admin posting one had no way of telling
 * whether anybody had read it, let alone what they made of it.
 *
 * One row per (announcement, account, emoji), which is what makes reacting
 * twice with the same emoji a no-op and lets the same account react with
 * several. `name` is either a Unicode emoji or the shortcode of one this
 * instance publishes — the same two things Mastodon accepts there.
 */
class Version1000Date20260912000005 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_announce_react')) {
			return null;
		}

		$table = $schema->createTable('social_announce_react');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('announcement_id', Types::INTEGER, [
			'notnull' => true,
		]);
		$table->addColumn('actor_id_prim', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		/** a Unicode emoji, or the shortcode of one this instance publishes */
		$table->addColumn('name', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		// both what makes reacting twice with the same emoji a no-op and the
		// index the two reads use: the counts for a page of announcements, and
		// whether this account is among them
		$table->addUniqueIndex(
			['announcement_id', 'actor_id_prim', 'name'], 'social_arct_aan'
		);

		return $schema;
	}
}
