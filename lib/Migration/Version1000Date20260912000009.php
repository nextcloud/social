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
 * Stories: `social_story` and `social_story_view`.
 *
 * A story is one picture that stops existing after a day. That is the whole of
 * the feature, and the expiry is not decoration -- it is the reason people post
 * things to it that they would not post to a timeline. So it is enforced in two
 * places that do not depend on each other: every read filters on `expires_at`,
 * and a cron job deletes what has expired. If the job never runs, nothing is
 * shown that should not be; if a read is ever written without the filter, the
 * job has already removed the row.
 *
 * `expires_at` is stored rather than computed from `creation` plus a constant,
 * so that changing how long a story lasts does not retroactively resurrect or
 * bury the ones already posted.
 *
 * The picture is a row in `social_cache_doc`, referenced by both its id and the
 * md5 of it -- a prim is one-way, and reading a story means fetching the
 * document by the id the cache is keyed by. A story is not a post: it has no `social_stream` row, is not addressed to
 * anybody, is not federated and has no ActivityPub identity at all. Pixelfed's
 * stories are local-only too, and giving them one would mean answering for what
 * a peer did with a copy after the day was up.
 *
 * `social_story_view` is who has seen one, which is the only state a story
 * carries beyond existing. One row per (story, viewer), unique on the pair, so
 * marking one seen twice is a no-op rather than a second row -- the route a
 * client calls as it scrolls is exactly the kind that gets called twice.
 *
 * The indexes are the reads:
 *
 *  - `social_story_ae` on `social_story(actor_id_prim, expires_at)` -- "the
 *    live stories of this account", which is what a profile and the carousel
 *    both ask, and the filter is always part of the question.
 *  - `social_story_e` on `social_story(expires_at)` -- what the cron job asks:
 *    everything due across every account, which the index above cannot answer
 *    because its leading column is the account.
 *  - `social_sv_sa` unique on `social_story_view(story_id, actor_id_prim)` --
 *    both what makes a repeated "seen" a no-op and the index that answers
 *    whether this viewer has seen this story.
 */
class Version1000Date20260912000009 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_story')) {
			$table = $schema->createTable('social_story');
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
			/**
			 * The picture, in `social_cache_doc`. Both forms are kept for the
			 * same reason `social_list` keeps both forms of its owner: a prim
			 * is one-way, and reading a story means fetching the document by
			 * the id the cache is keyed by, not by its hash.
			 */
			$table->addColumn('document_id', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('document_id_prim', Types::STRING, [
				'notnull' => false,
				'length' => 32,
			]);
			$table->addColumn('caption', Types::TEXT, [
				'notnull' => false,
			]);
			/** how long a client should hold on this one, in seconds */
			$table->addColumn('duration', Types::INTEGER, [
				'notnull' => true,
				'default' => 5,
			]);
			$table->addColumn('creation', Types::DATETIME, [
				'notnull' => false,
			]);
			/** stored, not computed: changing the lifetime must not move what is already posted */
			$table->addColumn('expires_at', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['actor_id_prim', 'expires_at'], 'social_story_ae');
			$table->addIndex(['expires_at'], 'social_story_e');
		}

		if (!$schema->hasTable('social_story_view')) {
			$table = $schema->createTable('social_story_view');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			$table->addColumn('story_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			$table->addColumn('actor_id_prim', Types::STRING, [
				'notnull' => false,
				'length' => 32,
			]);
			$table->addColumn('creation', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['story_id', 'actor_id_prim'], 'social_sv_sa');
		}

		return $schema;
	}
}
