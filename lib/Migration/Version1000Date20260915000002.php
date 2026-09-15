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
 * What an account has already brought over from somewhere else.
 *
 * The post importer writes a *new local post* for each post in an archive:
 * the original id belongs to the server it was written on, so it cannot be
 * kept — which means nothing in `social_stream` remembers where a post came
 * from, and a second run of the same archive would write every post again.
 * One row per (account, original id) is that memory. It is also what lets a
 * reply keep its parent: an archive names the parent by its original id, and
 * this is the only place that id is still written down.
 *
 * `source_id` is kept whole beside its hash. The hash is what the unique
 * index is on — an id is a URL and can be longer than any index this schema
 * would build on MySQL — and the full id is what a person reads when they ask
 * why a post was skipped.
 *
 * `stream_id_prim` names the post that was written. Nothing cascades: a post
 * the account later deletes leaves its row, deliberately. The row says "this
 * was imported", and importing it again because it was deleted here would be
 * the import undoing a decision the account has made.
 *
 * One index beyond the primary key:
 *
 *  - `social_imppost_src`, unique, on (`actor_id_prim`, `source_id_prim`) —
 *    the lookup the importer does for every item, and the constraint that
 *    makes a repeated import a no-op rather than a duplicate. Per account,
 *    because two people may each have a copy of the same post in their
 *    archives and each is importing their own.
 *
 * 32 + 32 bytes of `STRING` is well inside every supported database's key
 * limit, as the same pair is elsewhere in this schema.
 */
class Version1000Date20260915000002 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(CoreRequestBuilder::TABLE_IMPORTED_POSTS)) {
			return null;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_IMPORTED_POSTS);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('actor_id_prim', Types::STRING, [
			'notnull' => false,
			'length' => 32,
			'default' => '',
		]);
		$table->addColumn('source_id', Types::TEXT, [
			'notnull' => false,
			'default' => '',
		]);
		$table->addColumn('source_id_prim', Types::STRING, [
			'notnull' => false,
			'length' => 32,
			'default' => '',
		]);
		$table->addColumn('stream_id_prim', Types::STRING, [
			'notnull' => false,
			'length' => 32,
			'default' => '',
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['actor_id_prim', 'source_id_prim'], 'social_imppost_src');

		return $schema;
	}
}
