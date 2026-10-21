<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Stores the followers collection that authorized a non-public status. */
class Version1000Date20261021000003 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable(CoreRequestBuilder::TABLE_STATUS_DELETIONS)) {
			return null;
		}

		$table = $schema->getTable(CoreRequestBuilder::TABLE_STATUS_DELETIONS);
		if (!$table->hasColumn('audience_prim')) {
			$table->addColumn('audience_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
		}

		return $schema;
	}
}
