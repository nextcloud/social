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

/** Adds bounded, owner-scoped explicit recommendation feedback with undo. */
class Version1000Date20261023000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('social_rec_profile')) {
			$table = $schema->getTable('social_rec_profile');
			if (!$table->hasColumn('feedback_ver')) {
				$table->addColumn('feedback_ver', Types::BIGINT, ['default' => 0, 'notnull' => true]);
				$changed = true;
			}
		}

		if (!$schema->hasTable('social_rec_feedback')) {
			$table = $schema->createTable('social_rec_feedback');
			$table->addColumn('feedback_id', Types::STRING, ['length' => 36, 'notnull' => true]);
			$table->addColumn('actor_key', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('event_id', Types::STRING, ['length' => 36, 'notnull' => true]);
			$table->addColumn('scope', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('scope_key', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('operation', Types::STRING, ['length' => 20, 'notnull' => true]);
			$table->addColumn('revision', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('undo_until', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('active', Types::SMALLINT, ['default' => 1, 'notnull' => true]);
			$table->setPrimaryKey(['feedback_id']);
			$table->addUniqueIndex(['actor_key', 'event_id'], 'social_rec_feedback_event');
			$table->addIndex(['actor_key', 'scope', 'scope_key', 'revision'], 'social_rec_feedback_scope');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
