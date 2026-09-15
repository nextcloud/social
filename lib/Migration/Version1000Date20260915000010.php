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
 * Who is in the picture.
 *
 * Pixelfed's `MediaTag`, and a staple of every photo network there has been:
 * the people in a photograph are named on it, they are told it exists, and it
 * shows up under "photos of you" on their own profile. Somebody arriving from
 * Instagram expects it on the first day and this app had nothing at all.
 *
 * A tag is a fact about a **post**, not about a rectangle on an image. Pixelfed
 * keys its row on a media id and stores a `metadata` blob that in practice
 * holds only a version number; no client of its own draws a box. So the row
 * here names the post, which is what every read wants — the tagged people
 * under a photo, and the photos somebody is in — and the day a client wants a
 * rectangle is the day to add the two columns for it.
 *
 * Unique on (post, account): tagging one person in one post twice is one tag,
 * which is also what makes the write idempotent when a client sends its whole
 * list again.
 *
 * The second index is the one that answers "photos of you" — the tagged
 * account first, because that is what the query selects on. Without it that
 * page is a table scan on every profile visit.
 *
 * A deleted post takes its tags with it, through the same cascade every other
 * table hanging off a post goes through (`StreamRequest::deleteRelatedTo()`) —
 * a tag on a post that is gone is a row nothing can render and a name left
 * attached to a picture nobody can see.
 */
class Version1000Date20260915000010 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(CoreRequestBuilder::TABLE_MEDIA_TAGS)) {
			return null;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_MEDIA_TAGS);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		// the post, twice: by the id a client addresses it with, which is what
		// orders "photos of you" and what a client sends, and by the hash of
		// its ActivityPub id, which is the column every other table hanging off
		// a post is deleted by (`StreamRequest::deleteRelatedTo()`)
		$table->addColumn('stream_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('stream_id_prim', Types::STRING, [
			'notnull' => false,
			'length' => 32,
		]);
		$table->addColumn('actor_id', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('actor_id_prim', Types::STRING, [
			'notnull' => false,
			'length' => 32,
		]);
		// who did the tagging: the post's author today, kept so that a later
		// rule ("only the person who tagged you may untag you") has the fact
		// it would need rather than having to guess it
		$table->addColumn('tagger_id_prim', Types::STRING, [
			'notnull' => false,
			'length' => 32,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['stream_id', 'actor_id_prim'], 'social_mtag_sa');
		$table->addIndex(['actor_id_prim', 'stream_id'], 'social_mtag_as');

		return $schema;
	}
}
