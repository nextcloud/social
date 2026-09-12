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
 * Reports (moderation): one row per report, whether filed locally through
 * POST /api/v1/reports (`local` = 1) or received from a remote instance as a
 * federated Flag activity (`local` = 0, actor_id is the reporting instance's
 * actor). status_ids is a JSON list of reported status ids.
 */
class Version1000Date20260908000003 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_report')) {
			return null;
		}

		$table = $schema->createTable('social_report');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('actor_id', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('account_id', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('status_ids', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('comment', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('category', Types::STRING, [
			'notnull' => true,
			'length' => 31,
			'default' => 'other',
		]);
		$table->addColumn('local', Types::SMALLINT, [
			'notnull' => true,
			'default' => 1,
			'length' => 1,
		]);
		$table->addColumn('resolved', Types::SMALLINT, [
			'notnull' => true,
			'default' => 0,
			'length' => 1,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['resolved'], 'social_rep_res');

		return $schema;
	}
}
