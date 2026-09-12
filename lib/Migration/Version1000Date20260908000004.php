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
 * Profile metadata fields: the name/value table under the bio, federated as
 * PropertyValue attachments. The canonical copy for local actors lives on
 * their actor row as JSON.
 */
class Version1000Date20260908000004 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_actor')) {
			$table = $schema->getTable('social_actor');
			if (!$table->hasColumn('fields')) {
				$table->addColumn('fields', Types::TEXT, [
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}
}
