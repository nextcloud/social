<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Where somebody stopped watching.
 *
 * Its own step rather than a second table in the one before it, because that
 * one has already run wherever this branch has been deployed and a migration
 * that has run never runs again: a table added to it afterwards is a table
 * nobody gets.
 */
class Version1000Date20260916000005 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Where somebody stopped watching. PeerTube's `WatchAction`, and a fact
		// about a reader rather than about a video: it is never federated, and
		// a count that arrived from another server would be a number about
		// their readers. One row per (post, viewer), which is what makes
		// "continue watching" a list rather than a history of every play.
		if (!$schema->hasTable(CoreRequestBuilder::TABLE_WATCH)) {
			$table = $schema->createTable(CoreRequestBuilder::TABLE_WATCH);
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			$table->addColumn('stream_id_prim', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('actor_id_prim', Types::STRING, ['notnull' => false, 'length' => 32]);
			/** how many seconds in, and how long the video runs */
			$table->addColumn('position', Types::INTEGER, ['notnull' => false, 'default' => 0]);
			$table->addColumn('duration', Types::INTEGER, ['notnull' => false, 'default' => 0]);
			$table->addColumn('last_update', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['stream_id_prim', 'actor_id_prim'], 'social_watch_sa');
			// "what this reader was in the middle of", newest first, which is
			// the only read there is
			$table->addIndex(['actor_id_prim', 'last_update'], 'social_watch_al');
		}

		return $schema;
	}
}
