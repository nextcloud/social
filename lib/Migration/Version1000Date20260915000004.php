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
 * The posts waiting for a moderator to look at them.
 *
 * What is stored is the **request**, not the post: the same thing
 * `social_scheduled` holds, replayed through `PostService::createPost()` when
 * it is approved. Nothing is written to `social_stream` while a post is held,
 * and that is the point. A held post that existed as a row with a flag on it
 * would be one forgotten predicate away from a timeline, a hashtag page, a
 * profile or an outbox — and this app has shipped that leak before. A post
 * that is not in the table cannot be read out of it by code that has not been
 * written yet.
 *
 * `reason` is which rule held it, so the queue can say why and an
 * administrator can tell "this account is new" from "this reads like spam".
 *
 * `digest` is the md5 of the account and the text, and it is unique. A client
 * that is told its post was held will be pressed again by its user, and the
 * Pixelfed app retries a 422 by itself; without this, one post held once would
 * be a queue of twenty identical rows for a moderator to work through. The
 * second attempt finds the first and changes nothing.
 *
 * One index beyond that:
 *
 *  - `social_hold_actor` on (`actor_id_prim`, `id`) — one account's own held
 *    posts, which is both what the author is shown and how the per-account cap
 *    is counted.
 *
 * The queue itself is read in `id` order off the primary key: it is drained by
 * people, and an instance whose moderators have let it grow past a page has a
 * problem no index solves.
 */
class Version1000Date20260915000004 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(CoreRequestBuilder::TABLE_POST_HOLD)) {
			return null;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_POST_HOLD);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('actor_id', Types::TEXT, [
			'notnull' => false,
			'default' => '',
		]);
		$table->addColumn('actor_id_prim', Types::STRING, [
			'notnull' => false,
			'length' => 32,
			'default' => '',
		]);
		$table->addColumn('params', Types::TEXT, [
			'notnull' => false,
			'default' => '',
		]);
		$table->addColumn('reason', Types::STRING, [
			'notnull' => false,
			'length' => 31,
			'default' => '',
		]);
		$table->addColumn('digest', Types::STRING, [
			'notnull' => false,
			'length' => 32,
			'default' => '',
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['digest'], 'social_hold_digest');
		$table->addIndex(['actor_id_prim', 'id'], 'social_hold_actor');

		return $schema;
	}
}
