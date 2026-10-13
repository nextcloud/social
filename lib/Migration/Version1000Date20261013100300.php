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
 * `social_atproto_plc_log.dead` and `.reason`: when the directory refused
 * or nullified an operation, and what it said. A dead operation is kept
 * for the record, never sent again, and no later operation follows it.
 */
class Version1000Date20261013100300 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('social_atproto_plc_log')) {
			return null;
		}
		$table = $schema->getTable('social_atproto_plc_log');
		if ($table->hasColumn('dead')) {
			return null;
		}
		$table->addColumn('dead', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('reason', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);

		return $schema;
	}
}
