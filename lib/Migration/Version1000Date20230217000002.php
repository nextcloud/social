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

class Version1000Date20230217000002 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_stream')) {
			$table = $schema->getTable('social_stream');

			if (!$table->hasColumn('visibility')) {
				$table->addColumn(
					'visibility', Types::STRING,
					[
						'notnull' => false,
						'length' => 31,
						'default' => ''
					]
				);
			}
		}


		// fix nid as primary on social_cache_actor
		if ($schema->hasTable('social_cache_actor')) {
			$table = $schema->getTable('social_cache_actor');

			if (!$table->hasColumn('nid')) {
				$table->addColumn(
					'nid', Types::BIGINT,
					[
						'autoincrement' => true,
						'notnull' => true,
						'length' => 14,
						'unsigned' => true,
					]
				);
				$table->setPrimaryKey(['nid']);
			}
		}


		if ($schema->hasTable('social_cache_doc')) {
			$table = $schema->getTable('social_cache_doc');

			if (!$table->hasColumn('nid')) {
				$table->addColumn(
					'nid', Types::BIGINT,
					[
						'autoincrement' => true,
						'notnull' => true,
						'length' => 14,
						'unsigned' => true,
					]
				);
				$table->setPrimaryKey(['nid']);
			}

			if (!$table->hasColumn('account')) {
				$table->addColumn(
					'account', Types::STRING,
					[
						'notnull' => true,
						'length' => 127,
					]
				);
			}

			if (!$table->hasColumn('meta')) {
				$table->addColumn(
					'meta', Types::TEXT,
					[
						'notnull' => true,
						'default' => '[]'
					]
				);
			}
			
			if (!$table->hasColumn('blurhash')) {
				$table->addColumn(
					'blurhash', Types::STRING,
					[
						'notnull' => true,
						'length' => 63,
						'default' => ''
					]
				);
			}

			if (!$table->hasColumn('description')) {
				$table->addColumn(
					'description', Types::TEXT,
					[
						'notnull' => true,
						'default' => ''
					]
				);
			}
		}

		return $schema;
	}
}
