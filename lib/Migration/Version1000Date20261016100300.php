<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_actor_relation.activity_id_prim`, indexed: the md5 of the id of
 * the `Block` a relation was written from, so an `Undo` that names the
 * block by its id alone finds it. Relations made here, and those stored
 * before, have none.
 */
class Version1000Date20261016100300 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable(CoreRequestBuilder::TABLE_ACTOR_RELATION)) {
			return null;
		}
		$table = $schema->getTable(CoreRequestBuilder::TABLE_ACTOR_RELATION);
		if ($table->hasColumn('activity_id_prim')) {
			return null;
		}

		$table->addColumn('activity_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
		$table->addIndex(['activity_id_prim'], 'social_ar_act');

		return $schema;
	}
}
