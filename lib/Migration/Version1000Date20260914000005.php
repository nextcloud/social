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
 * `social_gif`: the instance's own library of animated pictures.
 *
 * Kept the way custom emoji are — a row per picture and the bytes in appdata —
 * and for the same reason: it is a small set an administrator curates, served
 * to everybody on the instance, and it must not depend on a file staying where
 * somebody's Files happens to have put it.
 *
 * `slug` is what the URL names and what makes adding the same picture twice a
 * replacement rather than a second row nothing can tell from the first.
 * `title` is what the picker searches, because "the one with the cat" is how
 * anybody actually looks for one of these.
 */
class Version1000Date20260914000005 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_gif')) {
			return null;
		}

		$table = $schema->createTable('social_gif');

		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => '']);
		$table->addColumn('filename', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('media_type', Types::STRING, ['notnull' => true, 'length' => 63]);
		$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		// one picture per slug: re-adding one replaces it rather than leaving
		// two rows nothing can tell apart
		$table->addUniqueIndex(['slug'], 'social_gif_slug');

		return $schema;
	}
}
