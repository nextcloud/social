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
 * Locked accounts (manuallyApprovesFollowers): the flag lives on the local
 * actor row; new follows towards a locked account wait as follow requests.
 */
class Version1000Date20260908000002 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_actor')) {
			$table = $schema->getTable('social_actor');
			if (!$table->hasColumn('locked')) {
				$table->addColumn('locked', Types::SMALLINT, [
					'notnull' => true,
					'default' => 0,
					'length' => 1,
				]);
			}
		}

		return $schema;
	}
}
