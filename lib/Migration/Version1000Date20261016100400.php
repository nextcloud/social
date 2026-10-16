<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Cron\ThreadRoots;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\BackgroundJob\IJobList;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_stream.root_prim`: the prim of the root of the thread a direct
 * message belongs to, stored with it (`ConversationsRequest::storeRoot()`).
 *
 * A conversation is its thread, and the thread was found by walking
 * `in_reply_to` up a query a level. A chat is a chain of replies each to
 * the one before, so every read of the direct messages and every poll of
 * their badge walked it, and a chat longer than the walk was grouped under
 * wherever the walk stopped, which moved as the chat grew. Indexed, for the
 * thread's own posts (`getThread()`) and for moving replies that arrived
 * before their parent under its root.
 *
 * Null on the rows stored before it and on every post that is not a direct
 * message; the reads walk for a null row as before. The direct messages
 * already stored are given theirs by `Cron\ThreadRoots`, queued here.
 */
class Version1000Date20261016100400 extends SimpleMigrationStep {
	public function __construct(
		private IJobList $jobList,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable(CoreRequestBuilder::TABLE_STREAM)) {
			return null;
		}
		$table = $schema->getTable(CoreRequestBuilder::TABLE_STREAM);
		if ($table->hasColumn('root_prim')) {
			return null;
		}

		$table->addColumn('root_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
		$table->addIndex(['root_prim'], 'social_s_root');

		return $schema;
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$this->jobList->has(ThreadRoots::class, null)) {
			$this->jobList->add(ThreadRoots::class);
		}
	}
}
