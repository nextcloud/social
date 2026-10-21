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

/** Keeps visibility and the original audience for status deletion events. */
class Version1000Date20261021000002 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable(CoreRequestBuilder::TABLE_STATUS_DELETIONS)) {
			return null;
		}

		$table = $schema->getTable(CoreRequestBuilder::TABLE_STATUS_DELETIONS);
		if (!$table->hasColumn('visibility')) {
			$table->addColumn('visibility', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'public']);
		}
		if (!$table->hasColumn('recipients')) {
			$table->addColumn('recipients', Types::TEXT, ['notnull' => false]);
		}

		return $schema;
	}
}
