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
 * What a year of federating leaves behind, and what the two maintenance
 * queries need to cope with it.
 *
 * `social_cache_actor.sync_attempt` and `sync_failures`: when this instance
 * last tried to refresh a cached remote actor (unix time, 0 for never) and
 * how many attempts in a row have failed since the last one that worked.
 * The refresh used to select on `creation`, which for a remote actor is the
 * `published` date its own instance reports — years old and never moving —
 * so the same fifty rows were due on every run, and if their instance was
 * dead the refresh never got past them. The attempt is now what is stamped,
 * whether it worked or not, and the oldest attempt goes first; the failures
 * count is what the backoff and the give-up threshold read. Integers rather
 * than a datetime so that "never" is 0 and sorts the same on every database,
 * where a NULL datetime does not. Indexed with `local`, which is the other
 * predicate of the query that orders on it.
 *
 * `social_req_queue` gets `(status, priority, tries, last)`: the drain
 * selects on `status` and orders on the other three, and the only index it
 * had was `(status, id)`, so every pass sorted the whole standby set to pick
 * two hundred rows.
 */
class Version1000Date20260914000003 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('social_cache_actor')) {
			$table = $schema->getTable('social_cache_actor');
			if (!$table->hasColumn('sync_attempt')) {
				$table->addColumn('sync_attempt', Types::BIGINT, [
					'notnull' => true,
					'default' => 0,
					'unsigned' => true,
				]);
				$changed = true;
			}

			if (!$table->hasColumn('sync_failures')) {
				$table->addColumn('sync_failures', Types::INTEGER, [
					'notnull' => true,
					'default' => 0,
					'unsigned' => true,
				]);
				$changed = true;
			}

			if (!$table->hasIndex('social_ca_lsa')) {
				$table->addIndex(['local', 'sync_attempt'], 'social_ca_lsa');
				$changed = true;
			}
		}

		if ($schema->hasTable('social_req_queue')) {
			$table = $schema->getTable('social_req_queue');
			if (!$table->hasIndex('social_rq_sptl')) {
				$table->addIndex(['status', 'priority', 'tries', 'last'], 'social_rq_sptl');
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
