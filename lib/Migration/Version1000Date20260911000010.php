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
 * The hashtags an account pins to its own profile.
 *
 * One row per (account, tag), which is Mastodon's featured tags: a handful of
 * hashtags an account says it posts about, shown on its profile with a count
 * of how often it has used each. Not to be confused with `social_followed_tag`
 * next door — that is a tag an account wants in its *timeline*, this is a tag
 * an account wants on its *profile*, and an account routinely has one without
 * the other.
 *
 * `actor_id`/`actor_id_prim` are the pair every other account-keyed table here
 * carries: the id as it federates, and its md5 as everything joins on.
 * `hashtag` is the tag with no leading `#`, lowercased, and no wider than
 * `social_stream_tag.hashtag` — a featured tag that did not fit there could be
 * pinned and would count zero posts forever, because nothing it matched could
 * have been stored.
 *
 * The unique index on the pair is both halves of the feature at once: it is
 * what makes featuring a tag twice a no-op, and it is the index both readers
 * use — the owner's own list, and the public list on somebody's profile, which
 * are the same query with a different account. No index on `hashtag` alone:
 * nothing asks who features a tag.
 *
 * `id` is not the key anything looks a row up by; it is what
 * `DELETE /api/v1/featured_tags/:id` names, and an autoincrement column is the
 * only thing in the row that is stable (a tag can be unfeatured and featured
 * again, and `creation` is not unique).
 *
 * There is no `statuses_count` or `last_status_at` column, though Mastodon's
 * FeaturedTag entity carries both. They are counted from `social_stream_tag`
 * when the profile is read, which is a count over an index that already
 * exists (`Version1000Date20260910000001` added it) — a stored counter would
 * have to be maintained by every write path that can create, delete or edit a
 * post, and the one that was missed would be wrong forever.
 *
 * Verified against DBAL 3.10 as shipped in the server's 3rdparty:
 *
 *  - MySQL/MariaDB: one `CREATE TABLE … (id BIGINT UNSIGNED AUTO_INCREMENT
 *    NOT NULL, actor_id LONGTEXT DEFAULT NULL, actor_id_prim VARCHAR(32) NOT
 *    NULL, hashtag VARCHAR(127) NOT NULL, creation DATETIME DEFAULT NULL,
 *    UNIQUE INDEX social_feat_ah (actor_id_prim, hashtag), PRIMARY KEY(id)) …
 *    ENGINE = InnoDB`. The unique index is 159 characters wide, well inside
 *    InnoDB's 3072-byte key limit even at four bytes a character — the same
 *    pair of widths `social_followed_tag` and `social_stream_tag` carry.
 *  - PostgreSQL: `CREATE TABLE` (`BIGSERIAL`, `TEXT`, `VARCHAR`,
 *    `TIMESTAMP(0) WITHOUT TIME ZONE`) plus a separate `CREATE UNIQUE INDEX`.
 *  - Oracle: `CREATE TABLE` (`NUMBER(20)`, `CLOB`, `VARCHAR2`,
 *    `TIMESTAMP(0)`), the PL/SQL block and sequence/trigger pair DBAL emits
 *    for an autoincrement column, then the `CREATE UNIQUE INDEX`.
 *  - SQLite: `CREATE TABLE` (`INTEGER PRIMARY KEY AUTOINCREMENT`, `CLOB`)
 *    plus the `CREATE UNIQUE INDEX`.
 *
 * A new table on every platform, so nothing is rewritten and nothing is
 * locked: the cost of this step does not depend on the size of the instance.
 * A second run produces no statement anywhere — the step returns null before
 * it asks for anything.
 */
class Version1000Date20260911000010 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_featured_tag')) {
			return null;
		}

		$table = $schema->createTable('social_featured_tag');
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
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('hashtag', Types::STRING, [
			'notnull' => true,
			'length' => 127,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['actor_id_prim', 'hashtag'], 'social_feat_ah');

		return $schema;
	}
}
