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
 * Blocking and muting: one row per (local actor, target actor, relation type).
 *
 * type is 'block' (local actor blocks target), 'mute' (local actor mutes target;
 * `notifications` decides whether the target's notifications are hidden too) or
 * 'blocked_by' (a remote target blocked the local actor). The unique index makes
 * the timeline anti-join O(1) per row and block/mute operations idempotent.
 */
class Version1000Date20260907000002 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_actor_relation')) {
			return null;
		}

		$table = $schema->createTable('social_actor_relation');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('actor_id_prim', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('object_id', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('object_id_prim', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('type', Types::STRING, [
			'notnull' => true,
			'length' => 15,
		]);
		$table->addColumn('notifications', Types::BOOLEAN, [
			'notnull' => false,
			'default' => true,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['actor_id_prim', 'object_id_prim', 'type'], 'social_ar_aot');
		$table->addIndex(['actor_id_prim', 'type'], 'social_ar_at');

		return $schema;
	}
}
