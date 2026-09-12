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
 * Indexes for the timeline hot path, and the two missing primary keys.
 *
 * The home timeline joins `social_follow` (by `actor_id_prim`, `accepted`) against
 * `social_stream_dest` (by `actor_id`), and both the hashtag trends and the
 * "since" timeline scan `social_stream.published_time` — none of which had a
 * usable index, so each ran a full scan of the largest tables on the instance.
 * `social_stream_dest` and `social_stream_tag` also had no primary key, which
 * breaks Galera/`pxc_strict_mode` and degrades row-based replication.
 */
class Version1000Date20260907000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('social_follow')) {
			$table = $schema->getTable('social_follow');
			if (!$table->hasIndex('social_f_aa')) {
				$table->addIndex(['actor_id_prim', 'accepted'], 'social_f_aa');
				$changed = true;
			}
		}

		if ($schema->hasTable('social_stream_dest')) {
			$table = $schema->getTable('social_stream_dest');
			if (!$table->hasIndex('social_sd_at')) {
				$table->addIndex(['actor_id', 'type'], 'social_sd_at');
				$changed = true;
			}
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, [
					'autoincrement' => true,
					'notnull' => true,
					'length' => 11,
					'unsigned' => true,
				]);
				$table->setPrimaryKey(['id']);
				$changed = true;
			}
		}

		if ($schema->hasTable('social_stream_tag')) {
			$table = $schema->getTable('social_stream_tag');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, [
					'autoincrement' => true,
					'notnull' => true,
					'length' => 11,
					'unsigned' => true,
				]);
				$table->setPrimaryKey(['id']);
				$changed = true;
			}
		}

		if ($schema->hasTable('social_stream')) {
			$table = $schema->getTable('social_stream');
			if (!$table->hasIndex('social_s_pub')) {
				$table->addIndex(['published_time'], 'social_s_pub');
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
