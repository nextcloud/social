<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Storage for pending RFC 8628 authorizations. */
class Version1000Date20261020000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('social_device_auth')) {
			return null;
		}
		$table = $schema->createTable('social_device_auth');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('device_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('user_code', Types::STRING, ['length' => 8, 'notnull' => true]);
		$table->addColumn('client_id', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('scopes', Types::TEXT, ['notnull' => true]);
		$table->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
		$table->addColumn('user_id', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
		$table->addColumn('poll_interval', Types::INTEGER, ['notnull' => true, 'default' => 5, 'unsigned' => true]);
		$table->addColumn('last_poll', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
		$table->addColumn('expires', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('created', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['device_hash'], 'social_dauth_hash');
		$table->addUniqueIndex(['user_code'], 'social_dauth_code');
		$table->addIndex(['expires'], 'social_dauth_exp');
		return $schema;
	}
}
