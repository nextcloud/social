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
 * `social_video_views` and `social_video_seen`: how many times a video posted
 * here was watched, and the little needed to count each viewer once a day.
 *
 * `social_video_views` is the number, one row per video, unique on the post:
 * a counter moved in SQL rather than a row per view, so it costs the same
 * whether a video was watched ten times or a million.
 *
 * `social_video_seen` is today's dedupe and nothing else: one row per (video,
 * viewer) for the current day, the viewer named only by a keyed hash of the
 * day, the video and the account or session, so a row cannot be matched to a
 * person or to the same person's row of another day or video. Unique on
 * (post, hash), which is what makes a second view the same day a no-op;
 * indexed on `day` for the cron that drops the rows of the days before
 * (`Cron\Cache`).
 */
class Version1000Date20261016103300 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('social_video_views')) {
			$table = $schema->createTable('social_video_views');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 20, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => '']);
			$table->addColumn('views', Types::BIGINT, ['length' => 20, 'notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['stream_id_prim'], 'social_vviews_s');
			$changed = true;
		}

		if (!$schema->hasTable('social_video_seen')) {
			$table = $schema->createTable('social_video_seen');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 20, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => '']);
			$table->addColumn('viewer_hash', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
			$table->addColumn('day', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['stream_id_prim', 'viewer_hash'], 'social_vseen_sv');
			$table->addIndex(['day'], 'social_vseen_d');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
