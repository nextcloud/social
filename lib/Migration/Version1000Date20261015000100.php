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
 * `social_starter_pack`: the starter packs other servers publish, read in
 * to be offered here: the collections of the fediverse (FEP-7aa9,
 * Mastodon's Collections) and Bluesky's suggested starter packs, told apart
 * by `origin`.
 *
 * One row per collection, unique on the hash of its id, which is how a
 * collection read again replaces itself, and on its slug, which is the path
 * segment the starter-pack routes take. `members` is the JSON list of the
 * actor ids whose inclusion was verified, in the curator's order; a pack has
 * at most a few dozen, and nothing queries into it.
 */
class Version1000Date20261015000100 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('social_starter_pack')) {
			return null;
		}

		$table = $schema->createTable('social_starter_pack');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 20, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uri', Types::TEXT, ['notnull' => false]);
		$table->addColumn('uri_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
		$table->addColumn('slug', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('origin', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'fediverse']);
		$table->addColumn('curator', Types::TEXT, ['notnull' => false]);
		$table->addColumn('curator_acct', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
		$table->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
		$table->addColumn('summary', Types::TEXT, ['notnull' => false]);
		$table->addColumn('topic', Types::STRING, ['length' => 191, 'notnull' => true, 'default' => '']);
		$table->addColumn('language', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => '']);
		$table->addColumn('url', Types::TEXT, ['notnull' => false]);
		$table->addColumn('members', Types::TEXT, ['notnull' => false]);
		$table->addColumn('fetched', Types::BIGINT, ['length' => 20, 'notnull' => true, 'default' => 0, 'unsigned' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uri_prim'], 'social_sp_uri');
		$table->addUniqueIndex(['slug'], 'social_sp_slug');

		return $schema;
	}
}
