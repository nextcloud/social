<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20230407000001 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// fix nid as primary on social_cache_actor
		if ($schema->hasTable('social_cache_actor')) {
			$table = $schema->getTable('social_cache_actor');

			if (!$table->hasColumn('details_update')) {
				$table->addColumn(
					'details_update', Types::DATETIME,
					[
						'notnull' => false
					]
				);
			}
		}

		return $schema;
	}
}
