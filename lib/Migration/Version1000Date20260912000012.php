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
 * Places: `social_place`, and `social_stream.place_id` pointing at one.
 *
 * A place is where a post was taken. Pixelfed shows it under the picture and
 * lets you browse by it, and it is the last of that app's organising features
 * this one has no answer for.
 *
 * **No geocoder ships with this.** Nothing here calls out to Nominatim, Google
 * or anyone else, and that is a decision rather than an omission: geocoding a
 * post means sending somebody's location to a third party at the moment they
 * are deciding whether to publish it, which is precisely the thing the Exif
 * stripping in this same release exists to prevent. A place is instead either
 * one this instance has already seen -- which is what the search route offers --
 * or one the client names outright, with coordinates it already had.
 *
 * `social_place` is shared: two posts taken at the same place point at one row,
 * which is what makes "everything posted here" a single indexed lookup rather
 * than a scan for a matching string. Rows are deduplicated on
 * `(name_prim, country)` so that posting "Berlin" twice does not make two
 * Berlins; `name_prim` is the md5 of the lowercased name, because the name is
 * TEXT and a unique index on a TEXT column is not portable across the databases
 * this app supports.
 *
 * `lat`/`lon` are stored as strings, not floats. They come from a client and go
 * back out to a client unchanged; nothing here does arithmetic on them, and a
 * float column would silently round a coordinate somebody typed. A place with no
 * coordinates at all is allowed -- a name is enough to group posts by.
 *
 * `social_stream.place_id` is nullable and defaults to zero, which is "nowhere".
 * A place is never required and never inferred: a post has one because its
 * author said so.
 *
 * The indexes:
 *
 *  - `social_place_nc` unique on `social_place(name_prim, country)` -- the
 *    deduplication, and the lookup that decides whether a named place already
 *    exists before one is created.
 *  - `social_place_c` on `social_place(country)` -- browsing by country, which
 *    is the one facet Pixelfed's own place browser offers.
 *  - `social_s_place` on `social_stream(place_id)` -- "everything posted at this
 *    place". Its leading column is the place, so it cannot also answer "where
 *    was this post", but that one is the primary key of `social_place` reached
 *    through a value the post already carries.
 */
class Version1000Date20260912000012 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_place')) {
			$table = $schema->createTable('social_place');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			$table->addColumn('name', Types::TEXT, [
				'notnull' => false,
			]);
			/** md5 of the lowercased name: a unique index on TEXT is not portable */
			$table->addColumn('name_prim', Types::STRING, [
				'notnull' => false,
				'length' => 32,
			]);
			/** ISO 3166-1 alpha-2, or '' when the client did not say */
			$table->addColumn('country', Types::STRING, [
				'notnull' => false,
				'length' => 2,
				'default' => '',
			]);
			/** strings, not floats: they round-trip from a client unchanged */
			$table->addColumn('lat', Types::STRING, [
				'notnull' => false,
				'length' => 32,
				'default' => '',
			]);
			$table->addColumn('lon', Types::STRING, [
				'notnull' => false,
				'length' => 32,
				'default' => '',
			]);
			$table->addColumn('creation', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['name_prim', 'country'], 'social_place_nc');
			$table->addIndex(['country'], 'social_place_c');
		}

		if ($schema->hasTable('social_stream')) {
			$table = $schema->getTable('social_stream');
			if (!$table->hasColumn('place_id')) {
				$table->addColumn('place_id', Types::BIGINT, [
					'notnull' => false,
					'length' => 11,
					'unsigned' => true,
					'default' => 0,
				]);
			}
			if (!$table->hasIndex('social_s_place')) {
				$table->addIndex(['place_id'], 'social_s_place');
			}
		}

		return $schema;
	}
}
