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

/** Persists the reader's For You onboarding progress and content balance. */
class Version1000Date20261023000004 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('social_rec_profile')) {
			return null;
		}

		$table = $schema->getTable('social_rec_profile');
		$changed = false;
		if (!$table->hasColumn('content_balance')) {
			$table->addColumn('content_balance', Types::STRING, ['length' => 16, 'default' => 'balanced', 'notnull' => true]);
			$changed = true;
		}
		if (!$table->hasColumn('onboarding_step')) {
			$table->addColumn('onboarding_step', Types::SMALLINT, ['default' => 0, 'notnull' => true]);
			$changed = true;
		}
		if (!$table->hasColumn('onboarding_complete')) {
			$table->addColumn('onboarding_complete', Types::SMALLINT, ['default' => 0, 'notnull' => true]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
