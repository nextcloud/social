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

/** Additive storage for versioned For You preferences and reset generations. */
class Version1000Date20261022000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('social_rec_profile')) {
			$table = $schema->createTable('social_rec_profile');
			$table->addColumn('actor_key', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('profile_ver', Types::BIGINT, ['default' => 0, 'notnull' => true]);
			$table->addColumn('generation', Types::BIGINT, ['default' => 0, 'notnull' => true]);
			$table->addColumn('learning_mode', Types::STRING, ['length' => 16, 'default' => 'off', 'notnull' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['default' => 0, 'notnull' => true]);
			$table->setPrimaryKey(['actor_key']);
			$changed = true;
		}

		if (!$schema->hasTable('social_rec_pref')) {
			$table = $schema->createTable('social_rec_pref');
			$table->addColumn('actor_key', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('topic_id', Types::STRING, ['length' => 127, 'notnull' => true]);
			$table->addColumn('mode', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['default' => 0, 'notnull' => true]);
			$table->addColumn('generation', Types::BIGINT, ['default' => 0, 'notnull' => true]);
			$table->setPrimaryKey(['actor_key', 'topic_id']);
			$table->addIndex(['topic_id', 'mode'], 'social_rec_topic_mode');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
