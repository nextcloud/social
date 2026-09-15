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
 * Who has opened a post.
 *
 * A story has had a view count since it was written, and a post — the thing
 * people actually agonise over — has had none: an author could see three likes
 * and had no way to know whether that was three out of five or three out of
 * four hundred. Pixelfed counts this and shows the author.
 *
 * **What is counted is deliberately narrow: a post's own page, opened by a
 * signed-in account that is not its author.** Not an impression in a timeline
 * — a post scrolled past in a feed has not been read, counting it would make
 * the number meaningless, and it would mean a row written for every post on
 * every page of every timeline.
 *
 * One row per (post, viewer) and unique on the pair, so the number is people
 * rather than visits: an author refreshing their own notifications does not
 * inflate anybody's count, and neither does a reader coming back to a thread.
 * The row is the smallest it can be — two hashes and a date — because there
 * will be a great many of them, and it carries no more about the viewer than
 * the fact that they opened it.
 */
class Version1000Date20260915000007 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(CoreRequestBuilder::TABLE_STREAM_VIEWS)) {
			return null;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_STREAM_VIEWS);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('stream_id_prim', Types::STRING, [
			'notnull' => false,
			'length' => 32,
			'default' => '',
		]);
		$table->addColumn('actor_id_prim', Types::STRING, [
			'notnull' => false,
			'length' => 32,
			'default' => '',
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		// both what makes a second look a no-op and the index the count reads
		$table->addUniqueIndex(['stream_id_prim', 'actor_id_prim'], 'social_sview_pair');

		return $schema;
	}
}
