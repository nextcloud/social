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
 * What somebody said back to a story.
 *
 * A story could be watched and nothing else. Pixelfed has three ways of
 * answering one — a view receipt, an emoji reaction and a written reply — and
 * its inbox has a verb for each: `View`, `Story:Reaction` and `Story:Reply`.
 * This app sent none of them and understood none of them, so a story posted to
 * Pixelfed followers came back silent: no "seen by", no reactions, and a reply
 * typed into the Pixelfed app arriving nowhere.
 *
 * One table for reactions and replies, because they are the same row with a
 * different word on it: who, about which story, what they said. Pixelfed keeps
 * them apart by a `type` on its status and so does this.
 *
 * A reply is **not** a post. It is not in a timeline, not on a profile, not in
 * an outbox and not federated onward — it is a private answer to something
 * that disappears in a day, and the one person who is told is the poster.
 * Pixelfed makes it a direct message; here it stays beside the story, which
 * means it goes when the story goes rather than outliving what it was about.
 *
 * `source_id_prim` is unique, which is what makes a redelivery one row: an
 * inbox is retried, and a reaction counted twice would be two reactions.
 */
class Version1000Date20260915000009 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(CoreRequestBuilder::TABLE_STORY_REACTS)) {
			return null;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_STORY_REACTS);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		// the local row of the story, not its address: a reaction to a story
		// this instance does not hold is a reaction to nothing
		$table->addColumn('story_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('actor_id', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('actor_id_prim', Types::STRING, [
			'notnull' => false,
			'length' => 32,
		]);
		// 'reaction' or 'reply'
		$table->addColumn('type', Types::STRING, [
			'notnull' => false,
			'length' => 15,
			'default' => '',
		]);
		$table->addColumn('content', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('source_id', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('source_id_prim', Types::STRING, [
			'notnull' => false,
			'length' => 32,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		// the one read there is: everything said about one story, oldest first
		$table->addIndex(['story_id', 'id'], 'social_stra_si');
		// how many times one account has answered one story, which is what the
		// cap is counted on
		$table->addIndex(['story_id', 'actor_id_prim'], 'social_stra_sa');
		$table->addUniqueIndex(['source_id_prim'], 'social_stra_src');

		return $schema;
	}
}
