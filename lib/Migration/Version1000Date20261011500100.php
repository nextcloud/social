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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Brings tables created by a release before 0.23 to the shape a fresh install
 * has, where the squashed schema cannot.
 *
 * `Version1000Date20221118000002` adds what is missing to a table that exists
 * and leaves what is there alone. The steps it replaced also changed and
 * dropped things, and an instance that created its tables before them still
 * carries the old shape:
 *
 *  - indexes the history dropped: `afoa` and `aoa` on `social_follow`, which
 *    `social_f_oa_u` and `social_f_foa` replaced, and `social_f_oa`, which
 *    `social_f_oa_u` covers; `ipoha` on `social_stream`, whose uniqueness its
 *    first column already has; `ts` on `social_stream_dest`, which no query
 *    uses; and the unique index on `social_stream.nid` that duplicates the
 *    primary key. Each costs a write on every insert and answers nothing.
 *  - `social_client.app_client_secret` at 63 characters, too narrow for the
 *    digest stored in it. The squash widens it before the secrets are hashed;
 *    this covers an instance that ran the squash before it did.
 *  - `social_cache_doc.account` without its default.
 *  - `social_stream_dest` and `social_stream_tag` with an `id` that is not
 *    the primary key, which the squash left on PostgreSQL before it set one.
 *
 * Every change is guarded, so this is a no-op on a fresh install and on an
 * instance that is already current.
 */
class Version1000Date20261011500100 extends SimpleMigrationStep {
	/** Indexes the history dropped, by table. */
	private const STALE_INDEXES = [
		CoreRequestBuilder::TABLE_FOLLOWS => ['afoa', 'aoa', 'social_f_oa'],
		CoreRequestBuilder::TABLE_STREAM => ['ipoha'],
		CoreRequestBuilder::TABLE_STREAM_DEST => ['ts'],
	];

	private const CLIENT_SECRET_LENGTH = 127;

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		foreach (self::STALE_INDEXES as $tableName => $indexes) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}
			$table = $schema->getTable($tableName);
			foreach ($indexes as $index) {
				if ($table->hasIndex($index)) {
					$table->dropIndex($index);
					$changed = true;
				}
			}
		}

		if ($schema->hasTable(CoreRequestBuilder::TABLE_STREAM)) {
			$table = $schema->getTable(CoreRequestBuilder::TABLE_STREAM);
			// created without a name, so the database chose one
			foreach ($table->getIndexes() as $index) {
				if ($index->isUnique() && !$index->isPrimary() && $index->getColumns() === ['nid']) {
					$table->dropIndex($index->getName());
					$changed = true;
				}
			}
		}

		if ($schema->hasTable(CoreRequestBuilder::TABLE_CLIENT)) {
			$table = $schema->getTable(CoreRequestBuilder::TABLE_CLIENT);
			if ($table->hasColumn('app_client_secret')
				&& ($table->getColumn('app_client_secret')->getLength() ?? 0) < self::CLIENT_SECRET_LENGTH) {
				$table->getColumn('app_client_secret')->setLength(self::CLIENT_SECRET_LENGTH);
				$changed = true;
			}
		}

		if ($schema->hasTable(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS)) {
			$table = $schema->getTable(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS);
			if ($table->hasColumn('account') && $table->getColumn('account')->getDefault() === null) {
				$table->getColumn('account')->setDefault('');
				$changed = true;
			}
		}

		foreach ([CoreRequestBuilder::TABLE_STREAM_DEST, CoreRequestBuilder::TABLE_STREAM_TAGS] as $tableName) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}
			$table = $schema->getTable($tableName);
			if ($table->hasColumn('id') && !$table->hasPrimaryKey()) {
				$table->setPrimaryKey(['id']);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
