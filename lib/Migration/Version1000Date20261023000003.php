<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Keeps only bounded, instance-wide hourly provider health aggregates. */
class Version1000Date20261023000003 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('social_rec_discovery')) {
			return null;
		}

		$table = $schema->createTable('social_rec_discovery');
		$table->addColumn('provider', Types::STRING, ['length' => 16, 'notnull' => true]);
		$table->addColumn('hour_bucket', Types::BIGINT, ['notnull' => true]);
		foreach (['calls', 'requests', 'failed_requests', 'rate_limited', 'posts_seen', 'posts_stored', 'duration_ms', 'last_attempt', 'last_success'] as $column) {
			$table->addColumn($column, Types::BIGINT, ['default' => 0, 'notnull' => true]);
		}
		$table->setPrimaryKey(['provider', 'hour_bucket']);
		$table->addIndex(['hour_bucket'], 'social_rec_disc_hour');

		return $schema;
	}
}
