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
 * What the instance has decided about an account, as opposed to what one of
 * its users has.
 *
 * Per-user blocks and mutes already live in `social_actor_relation`. This is
 * the other kind: a moderator acting for everyone here, which is what the
 * reports panel has always described a problem about and never been able to
 * do anything about.
 */
class Version1000Date20260909000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_moderation')) {
			return null;
		}

		$table = $schema->createTable('social_moderation');
		$table->addColumn('actor_id_prim', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('actor_id', Types::STRING, [
			'notnull' => true,
			'length' => 1000,
		]);
		/** 'silence' keeps the account reachable but out of the public view;
		 *  'suspend' removes it from this instance entirely */
		$table->addColumn('level', Types::STRING, [
			'notnull' => true,
			'length' => 15,
		]);
		$table->addColumn('comment', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['actor_id_prim']);
		$table->addIndex(['level'], 'smlv');

		return $schema;
	}
}
