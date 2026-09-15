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
 * Two moderation tools this app did not have, and a network it federates with
 * does.
 *
 * **`social_moderation.force_sensitive`** — every post by this account is
 * marked sensitive, whatever the account said. It is the step between doing
 * nothing and silencing an account that keeps posting things people should be
 * asked before seeing: Mastodon's moderators have it, Pixelfed calls it `cw`,
 * and this app's answer was a 422 saying it had no such state. A column on the
 * decision that already exists, because it is one more thing decided about one
 * account and belongs with the silence and the suspension.
 *
 * **`social_media_block`** — a picture refused by its content, across the whole
 * instance. The one thing a moderator cannot do with any of the tools above is
 * stop a file coming back: an account is suspended, and the picture is posted
 * again by the next account, and the moderator is deleting the same image for
 * the third time. A hash is what identifies it, and `sha256` of the bytes is
 * what the upload path already has to read anyway.
 *
 * Unique on the hash — writing one twice is one row — with the reason and who
 * decided it beside it, because a blocklist nobody can explain in a year's
 * time is one nobody dares remove anything from.
 */
class Version1000Date20260915000006 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable(CoreRequestBuilder::TABLE_MODERATION)) {
			$table = $schema->getTable(CoreRequestBuilder::TABLE_MODERATION);
			if (!$table->hasColumn('force_sensitive')) {
				$table->addColumn('force_sensitive', Types::BOOLEAN, [
					'notnull' => false,
					'default' => false,
				]);
				$changed = true;
			}
		}

		if (!$schema->hasTable(CoreRequestBuilder::TABLE_MEDIA_BLOCKS)) {
			$table = $schema->createTable(CoreRequestBuilder::TABLE_MEDIA_BLOCKS);
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			// sha256, hex: 64 characters, and the same string the upload path
			// computes as it writes the file
			$table->addColumn('hash', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => '',
			]);
			$table->addColumn('reason', Types::TEXT, [
				'notnull' => false,
				'default' => '',
			]);
			$table->addColumn('moderator', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => '',
			]);
			$table->addColumn('blocked', Types::INTEGER, [
				'notnull' => false,
				'default' => 0,
				'unsigned' => true,
			]);
			$table->addColumn('creation', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['hash'], 'social_mediablock_h');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
