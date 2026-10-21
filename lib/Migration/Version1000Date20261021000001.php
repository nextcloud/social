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

/** Keeps a bounded cursor of public status deletions for live Mastodon streams. */
class Version1000Date20261021000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(CoreRequestBuilder::TABLE_STATUS_DELETIONS)) {
			return null;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_STATUS_DELETIONS);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('status_nid', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('hashtags', Types::TEXT, ['notnull' => true]);
		$table->addColumn('local', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
		$table->addColumn('media', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
		$table->setPrimaryKey(['id'], 'social_sdel_id');

		return $schema;
	}
}
