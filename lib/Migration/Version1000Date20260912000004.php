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
 * The instance's own custom emoji: `social_emoji`.
 *
 * `/api/v1/custom_emojis` answered `[]` unconditionally, and outbound posts
 * carried no `Emoji` tags — so emoji from every other instance rendered here
 * and this instance could publish none. The picture itself lives in appdata,
 * as a cached document does; the row is the shortcode, what it points at, and
 * whether a picker should offer it.
 */
class Version1000Date20260912000004 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_emoji')) {
			return null;
		}

		$table = $schema->createTable('social_emoji');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		/** what is written between colons, lowercase; `blobcat` in `:blobcat:` */
		$table->addColumn('shortcode', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		/** the picker's grouping, or '' for none */
		$table->addColumn('category', Types::STRING, [
			'notnull' => false,
			'length' => 64,
		]);
		/** the appdata file holding the picture */
		$table->addColumn('filename', Types::STRING, [
			'notnull' => true,
			'length' => 128,
		]);
		$table->addColumn('media_type', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		/** Mastodon's `visible_in_picker`: offered, or only usable by name */
		$table->addColumn('visible', Types::BOOLEAN, [
			'notnull' => false,
			'default' => true,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		// a shortcode names one picture: the uniqueness is what makes adding
		// the same one twice a replacement rather than a second row nothing
		// can tell from the first
		$table->addUniqueIndex(['shortcode'], 'social_emo_sc');

		return $schema;
	}
}
