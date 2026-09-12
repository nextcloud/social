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
 * The two index gaps left after Version1000Date20260910000001.
 *
 * `social_actor.user_id` had no index at all: the table carried its primary key
 * on `id_prim` and nothing else, while `ActorsRequest::getFromUserId()` — the
 * lookup that resolves which actor the logged-in user is, on effectively every
 * authenticated request — filters on `user_id`. Small on most instances, which
 * is why it was never felt, and a full scan on all of them.
 *
 * `social_hashtag` has five sortable trend columns and one index.
 * `HashtagsRequest::getTrending()` filters and orders on whichever window the
 * caller asked for, so `1h`, `12h`, `3d` and `10d` each scanned the table and
 * sorted the result while `1d` used `social_h_t1d`.
 *
 * Both are plain index additions against columns that already exist. On
 * MySQL/MariaDB and PostgreSQL they cost what they look like. On SQLite an
 * index cannot be added in place, so Doctrine rebuilds each table — both are
 * small, unlike the ten rebuilt by Version1000Date20260910000001.
 */
class Version1000Date20260912000001 extends SimpleMigrationStep {
	private const TREND_INDEXES = [
		'trend_1h' => 'social_h_t1h',
		'trend_12h' => 'social_h_t12h',
		'trend_3d' => 'social_h_t3d',
		'trend_10d' => 'social_h_t10d',
	];

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('social_actor')) {
			$table = $schema->getTable('social_actor');
			if ($table->hasColumn('user_id') && !$table->hasIndex('social_a_uid')) {
				$table->addIndex(['user_id'], 'social_a_uid');
				$changed = true;
			}
		}

		if ($schema->hasTable('social_hashtag')) {
			$table = $schema->getTable('social_hashtag');
			foreach (self::TREND_INDEXES as $column => $name) {
				if ($table->hasColumn($column) && !$table->hasIndex($name)) {
					$table->addIndex([$column], $name);
					$changed = true;
				}
			}
		}

		return $changed ? $schema : null;
	}
}
