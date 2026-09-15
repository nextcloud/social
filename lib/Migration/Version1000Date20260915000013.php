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
 * An account a team posts from.
 *
 * Pixelfed's answer to "several people, one voice" is its `Group*` family:
 * twenty models, still beta, and a second social graph beside the one it
 * already has. Nextcloud's answer is the one it has had all along — a group of
 * people who already work together — and this is that group given an account.
 *
 * It is the one thing in this whole comparison that Nextcloud can do and
 * Pixelfed cannot, because Pixelfed has no idea who works with whom.
 *
 * **The membership is the Nextcloud group, live.** There is no membership
 * table here, deliberately: a copy of a group is a copy that drifts, and the
 * drift is somebody who left the organisation still able to post as it.
 * Whether an account may post as a team is asked of the group manager at the
 * moment they try.
 *
 * `social_team_post` is the other half, and it is about accountability rather
 * than about display: a post from a team account says the team wrote it, and
 * inside the team somebody has to be able to find out which of them did. One
 * row per post, the author beside it, and who is shown it is a decision the
 * service makes — outside the team the team speaks with one voice, which is
 * the whole point of having one.
 */
class Version1000Date20260915000013 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(CoreRequestBuilder::TABLE_TEAMS)) {
			$table = $schema->createTable(CoreRequestBuilder::TABLE_TEAMS);
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			// the account the team posts from — an actor like any other, which
			// is what makes the follower list, the moderation and the
			// federation this app already has apply to it unchanged
			$table->addColumn('actor_id', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('actor_id_prim', Types::STRING, [
				'notnull' => false,
				'length' => 32,
			]);
			// the Nextcloud group whose members may post as it
			$table->addColumn('group_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => '',
			]);
			$table->addColumn('creation', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			// one account per team account, and the index the post path probes
			$table->addUniqueIndex(['actor_id_prim'], 'social_team_a');
			// and the index behind "which teams may this person post as",
			// which is the read on every page of the composer
			$table->addIndex(['group_id'], 'social_team_g');
		}

		if (!$schema->hasTable(CoreRequestBuilder::TABLE_TEAM_POSTS)) {
			$table = $schema->createTable(CoreRequestBuilder::TABLE_TEAM_POSTS);
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			$table->addColumn('stream_id_prim', Types::STRING, [
				'notnull' => false,
				'length' => 32,
			]);
			// who actually wrote it
			$table->addColumn('author_id', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('author_id_prim', Types::STRING, [
				'notnull' => false,
				'length' => 32,
			]);
			$table->addColumn('creation', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			// one author per post, and the index the read uses
			$table->addUniqueIndex(['stream_id_prim'], 'social_teamp_s');
		}

		return $schema;
	}
}
