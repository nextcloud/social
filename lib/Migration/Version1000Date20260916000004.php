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
 * Channels: the thing PeerTube cannot have a video without.
 *
 * This app publishes a one-video post as an ActivityPub `Video`, which is the
 * shape PeerTube ingests — and PeerTube refused every one of them, silently and
 * on its own side. Its builder resolves the channel a video belongs to by
 * looking for a **`Group`** in `attributedTo` and throws *"Cannot find
 * associated video channel"* when there is none; then it fetches that `Group`
 * and looks for a **`Person`** in *its* `attributedTo`, throwing *"Cannot find
 * account attributed to video channel"* when there is none. A Social account is
 * a `Person` and nothing else, so no video posted here has ever been ingestable
 * by a PeerTube.
 *
 * `social_channel` is that missing actor. A channel is an **actor like any
 * other** — key pair, inbox, outbox, followers, followable, moderatable — which
 * is the same design `social_team` uses and the reason neither needed the actor
 * machinery written again. What is new is only the type it is served as and who
 * owns it.
 *
 * `social_actor.actor_type` is how it is served as a `Group`. The type of a
 * local actor was derived from one flag (`bot`, which moves it between `Person`
 * and `Service`) and there was nowhere to say anything else. A column rather
 * than a prefix test on the user id, because what an actor *is* is a fact about
 * the actor and not a naming convention; `''` means "decide as before", which
 * is every row written until now.
 *
 * A `size` column goes onto `social_cache_doc` in the same step, for the same
 * feature, and `social_watch` beside it: see the comments on each below.
 *
 * Channels belong to an account, not to a person's Nextcloud user: the owner is
 * the `Person` actor, so a channel survives a rename and points at the same
 * thing the wire does.
 */
class Version1000Date20260916000004 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(CoreRequestBuilder::TABLE_ACTORS)) {
			$actors = $schema->getTable(CoreRequestBuilder::TABLE_ACTORS);
			if (!$actors->hasColumn('actor_type')) {
				// '' is "decide the way it was decided before" — the `bot` flag
				// choosing between Person and Service — and is what every row
				// written until now means
				$actors->addColumn('actor_type', Types::STRING, [
					'notnull' => false,
					'length' => 31,
					'default' => '',
				]);
			}
		}

		// How many bytes a stored file is. PeerTube's `isRemoteVideoUrlValid()`
		// wants `size` as an integer on every video file link and drops a link
		// without one, so a `Video` published from here had its only playable
		// file filtered out on arrival. It is a fact about the stored file that
		// nothing recorded, and measuring it meant a filesystem lookup per
		// serialisation; the per-account video quota wants the same number.
		if ($schema->hasTable(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS)) {
			$documents = $schema->getTable(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS);
			if (!$documents->hasColumn('size')) {
				$documents->addColumn('size', Types::BIGINT, [
					'notnull' => false,
					'length' => 15,
					'default' => 0,
					'unsigned' => true,
				]);
			}
		}

		if (!$schema->hasTable(CoreRequestBuilder::TABLE_CHANNELS)) {
			$table = $schema->createTable(CoreRequestBuilder::TABLE_CHANNELS);
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			/** the channel's own `Group` actor */
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('actor_id_prim', Types::STRING, ['notnull' => false, 'length' => 32]);
			/**
			 * The `Person` actor that owns it — the account, not the Nextcloud
			 * user id, so a channel points at the same thing the wire does and
			 * survives everything a rename does not touch.
			 */
			$table->addColumn('owner_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('owner_id_prim', Types::STRING, ['notnull' => false, 'length' => 32]);
			/** what it is called, and what it says it is about */
			$table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => '']);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			/**
			 * The one a video goes to when nobody chose. Every account that
			 * posts a video gets one made for it, because nobody should have
			 * to learn what a channel is in order to post a video.
			 */
			$table->addColumn('is_default', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			// one row per channel actor
			$table->addUniqueIndex(['actor_id_prim'], 'social_chan_aid');
			// "the channels of this account", which is every read there is
			$table->addIndex(['owner_id_prim'], 'social_chan_own');
		}

		// Where somebody stopped watching. PeerTube's `WatchAction`, and a fact
		// about a reader rather than about a video: it is never federated, and
		// a count that arrived from another server would be a number about
		// their readers. One row per (post, viewer), which is what makes
		// "continue watching" a list rather than a history of every play.
		if (!$schema->hasTable(CoreRequestBuilder::TABLE_WATCH)) {
			$table = $schema->createTable(CoreRequestBuilder::TABLE_WATCH);
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			$table->addColumn('stream_id_prim', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('actor_id_prim', Types::STRING, ['notnull' => false, 'length' => 32]);
			/** how many seconds in, and how long the video runs */
			$table->addColumn('position', Types::INTEGER, ['notnull' => false, 'default' => 0]);
			$table->addColumn('duration', Types::INTEGER, ['notnull' => false, 'default' => 0]);
			$table->addColumn('last_update', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['stream_id_prim', 'actor_id_prim'], 'social_watch_sa');
			// "what this reader was in the middle of", newest first, which is
			// the only read there is
			$table->addIndex(['actor_id_prim', 'last_update'], 'social_watch_al');
		}

		return $schema;
	}
}
