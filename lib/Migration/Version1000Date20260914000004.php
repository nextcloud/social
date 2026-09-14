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
 * `social_reaction`: who reacted to what, and with which emoji.
 *
 * A table of its own rather than another `type` in `social_action`, because a
 * reaction is not the same shape as a like or a boost. Those are a fact about
 * a pair — this account, that post — and the table's key says as much. A
 * reaction carries a third thing, the emoji, and one account may react to one
 * post several times over with different ones. There is nowhere in
 * `social_action` to put the emoji, and widening its key would change what a
 * like means.
 *
 * `emoji` is 63 bytes rather than a handful: a single emoji can be a long
 * grapheme cluster (a family with skin tones and zero-width joiners runs past
 * 30 bytes in UTF-8), and a shortcode for a custom emoji — `:blobcat:`, which
 * is what Misskey and Akkoma send for theirs — is a word.
 *
 * The unique index is what makes reacting idempotent: a duplicate delivery of
 * the same `EmojiReact`, which the Fediverse produces routinely, is refused by
 * the database rather than counted twice.
 */
class Version1000Date20260914000004 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_reaction')) {
			return null;
		}

		$table = $schema->createTable('social_reaction');

		$table->addColumn('id', Types::STRING, ['notnull' => false, 'length' => 1000]);
		$table->addColumn('id_prim', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('actor_id', Types::STRING, ['notnull' => false, 'length' => 1000]);
		$table->addColumn('actor_id_prim', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('object_id', Types::STRING, ['notnull' => false, 'length' => 1000]);
		$table->addColumn('object_id_prim', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('emoji', Types::STRING, ['notnull' => false, 'length' => 63, 'default' => '']);
		$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id_prim']);
		// one reaction per account per emoji per post; a redelivery is refused
		// here rather than counted again
		$table->addUniqueIndex(['actor_id_prim', 'object_id_prim', 'emoji'], 'social_react_aoe');
		// the reaction bar of a post, which is the read this table exists for
		$table->addIndex(['object_id_prim'], 'social_react_o');

		return $schema;
	}
}
