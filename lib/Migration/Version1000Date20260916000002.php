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
 * The relays this instance subscribes to: `social_relay`.
 *
 * A small instance sees only what the people on it follow, so its federated
 * timeline is empty on the first day and stays thin for months — there is
 * nobody here yet to have found anybody out there. A relay is the fediverse's
 * answer to that: an actor that rebroadcasts the public posts of every
 * instance subscribed to it, so a new server has something to read and its own
 * posts are seen by people who have never heard of it.
 *
 * One row per relay, and the row is the subscription rather than the relay: it
 * records the `Follow` this instance sent, whether the relay answered, and the
 * inbox to deliver to. `actor_id_prim` is the md5 of the actor id, because an
 * actor id is longer than an index may be on MySQL — the same reason every
 * other `_prim` column here exists.
 *
 * The subscription belongs to the *instance*, not to an account: the `Follow`
 * is signed by the instance actor, and what comes back is for everybody's
 * federated timeline. That is also why there is no owner column.
 */
class Version1000Date20260916000002 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(CoreRequestBuilder::TABLE_RELAYS)) {
			return null;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_RELAYS);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		/** the relay actor's own id, as its document claims it */
		$table->addColumn('actor_id', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('actor_id_prim', Types::STRING, [
			'notnull' => false,
			'length' => 32,
		]);
		/** where the Follow went and where the posts go */
		$table->addColumn('inbox', Types::TEXT, [
			'notnull' => false,
		]);
		/** 'pending', 'accepted' or 'rejected' */
		$table->addColumn('status', Types::STRING, [
			'notnull' => false,
			'length' => 15,
			'default' => '',
		]);
		/**
		 * The id of the Follow that was sent. An Accept names the activity it
		 * accepts, and matching on it is what stops an unrelated Accept from a
		 * server that happens to be a relay from enabling a subscription
		 * nobody asked for.
		 */
		$table->addColumn('follow_id', Types::TEXT, [
			'notnull' => false,
		]);
		/** why the last attempt failed, for the panel to show */
		$table->addColumn('error', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);
		/** when the relay last answered, or was last delivered to */
		$table->addColumn('last_update', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		// one subscription per relay: subscribing twice is the same row
		$table->addUniqueIndex(['actor_id_prim'], 'social_relay_aid');

		return $schema;
	}
}
