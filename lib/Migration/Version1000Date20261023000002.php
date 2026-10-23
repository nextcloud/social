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

/** Adds bounded aggregates and short-lived idempotency and impression records. */
class Version1000Date20261023000002 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('social_rec_signal')) {
			$table = $schema->createTable('social_rec_signal');
			$table->addColumn('actor_key', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('dimension', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('entity_key', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('fast_score', Types::FLOAT, ['default' => 0, 'notnull' => true]);
			$table->addColumn('slow_score', Types::FLOAT, ['default' => 0, 'notnull' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('evidence_count', Types::BIGINT, ['default' => 0, 'notnull' => true]);
			$table->setPrimaryKey(['actor_key', 'dimension', 'entity_key']);
			$table->addIndex(['actor_key', 'dimension'], 'social_rec_signal_owner');
			$changed = true;
		}

		if (!$schema->hasTable('social_rec_event')) {
			$table = $schema->createTable('social_rec_event');
			$table->addColumn('actor_key', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('event_id', Types::STRING, ['length' => 36, 'notnull' => true]);
			$table->addColumn('generation', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('kind', Types::STRING, ['length' => 24, 'notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['actor_key', 'event_id']);
			$table->addIndex(['created_at'], 'social_rec_event_expiry');
			$table->addIndex(['actor_key', 'generation'], 'social_rec_event_generation');
			$changed = true;
		}

		if (!$schema->hasTable('social_rec_seen')) {
			$table = $schema->createTable('social_rec_seen');
			$table->addColumn('actor_key', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('post_key', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('session_key', Types::STRING, ['length' => 36, 'notnull' => true]);
			$table->addColumn('generation', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('impressions', Types::SMALLINT, ['default' => 1, 'notnull' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['actor_key', 'post_key', 'session_key']);
			$table->addIndex(['actor_key', 'updated_at'], 'social_rec_seen_expiry');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
