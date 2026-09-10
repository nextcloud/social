<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\HashtagsRequest;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Sortable hashtag trend counters.
 *
 * `social_hashtag` kept the count for each window inside a JSON column, which
 * no supported database can be asked to sort on portably — so answering
 * "the ten most used hashtags in the last day" meant loading every hashtag the
 * instance has ever seen into PHP and sorting there, on every trends request
 * and on every dashboard widget render.
 *
 * The counts now also live in one integer column per window, written by the
 * same cron pass that writes the JSON. The JSON column stays: it is what the
 * API hands back, and it keeps this migration free of any backfill — the next
 * cron pass fills the columns in, and until then a trend reads as zero rather
 * than wrong.
 *
 * Columns are added with a default, so no table is rewritten with a NOT NULL
 * scan on PostgreSQL.
 */
class Version1000Date20260910000002 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_hashtag')) {
			return null;
		}

		$changed = false;
		$table = $schema->getTable('social_hashtag');
		foreach (HashtagsRequest::TREND_COLUMNS as $column) {
			if ($table->hasColumn($column)) {
				continue;
			}

			$table->addColumn($column, Types::INTEGER, [
				'notnull' => false,
				'default' => 0,
			]);
			$changed = true;
		}

		// the default window, the one both the trends endpoint and the
		// dashboard widget ask for without saying so
		if ($table->hasColumn('trend_1d') && !$table->hasIndex('social_h_t1d')) {
			$table->addIndex(['trend_1d'], 'social_h_t1d');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
