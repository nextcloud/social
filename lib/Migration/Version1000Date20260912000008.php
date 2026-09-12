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
 * Collections: `social_collection` and `social_collection_item`.
 *
 * A collection is an album an account curates out of its own posts -- Pixelfed's
 * headline organising feature, and the one an account moving here notices is
 * missing the moment it looks at its own profile. It is not a timeline and not a
 * list: the posts are the owner's, the order is chosen rather than
 * chronological, and the whole thing has a title of its own.
 *
 * Two tables, for the same reason lists use two: the collection and its contents
 * have different lifetimes and different readers.
 *
 * `actor_id_prim` is the md5 of the owner's id, the key every other table joins
 * actors by, and the full `actor_id` is kept alongside it exactly as
 * `social_list` keeps it -- a prim is one-way, and a collection has to be able
 * to say whose it is without a second lookup.
 *
 * `visibility` reuses the stream's own vocabulary (`public`, `followers`,
 * `direct`) rather than inventing a second one, so that "who may see this
 * collection" is answered by the same words as "who may see this post". Only
 * `public` and `followers` are meaningful for a collection; a direct one would
 * have nobody to be direct to.
 *
 * `social_collection_item` holds one row per (collection, post). The post is
 * referenced by `stream_id_prim` -- the md5 of its ActivityPub id, which is what
 * `social_stream.id_prim` is and what every other side table here points at --
 * so a collection survives a post being re-fetched and does not need the post's
 * numeric nid, which is local and can differ per instance.
 *
 * `position` is what makes it an album rather than a set: the owner arranges the
 * pictures and the order is the point. It is a plain integer with gaps allowed,
 * so moving one picture rewrites one row rather than renumbering the album.
 *
 * The indexes are the reads there are:
 *
 *  - `social_coll_a` on `social_collection(actor_id_prim)` -- "the collections
 *    of this account", which is what a profile draws and what
 *    `GET /api/v1/collections` answers. Every single-collection route is
 *    `WHERE id = ? AND actor_id_prim = ?` for a write, so the primary key finds
 *    the row and the owner is a predicate on it: a collection is never changed
 *    or deleted by anybody but the account that owns it.
 *  - `social_ci_cp` unique on `social_collection_item(collection_id, stream_id_prim)`
 *    -- the contents of one collection, in the order they are read, and unique
 *    so that adding a post that is already in the collection is a no-op rather
 *    than a second row. That is what makes `POST .../items` safe to retry.
 *  - `social_ci_s` on `social_collection_item(stream_id_prim)` -- the opposite
 *    question, "which collections is this post in", which the unique index
 *    cannot answer because its leading column is the collection. It is also
 *    what deleting a post has to use to clean up after itself.
 */
class Version1000Date20260912000008 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_collection')) {
			$table = $schema->createTable('social_collection');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
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
			$table->addColumn('title', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('description', Types::TEXT, [
				'notnull' => false,
			]);
			/** the stream's own vocabulary: `public` or `followers` */
			$table->addColumn('visibility', Types::STRING, [
				'notnull' => true,
				'length' => 15,
				'default' => 'public',
			]);
			$table->addColumn('creation', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('updated', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['actor_id_prim'], 'social_coll_a');
		}

		if (!$schema->hasTable('social_collection_item')) {
			$table = $schema->createTable('social_collection_item');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			$table->addColumn('collection_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			/** the md5 of the post's ActivityPub id: `social_stream.id_prim` */
			$table->addColumn('stream_id_prim', Types::STRING, [
				'notnull' => false,
				'length' => 32,
			]);
			/** chosen, not chronological; gaps allowed so a move rewrites one row */
			$table->addColumn('position', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('creation', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['collection_id', 'stream_id_prim'], 'social_ci_cp');
			$table->addIndex(['stream_id_prim'], 'social_ci_s');
		}

		return $schema;
	}
}
