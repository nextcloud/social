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
 * A video at more than one size.
 *
 * Until now a stored video was one file at whatever height it was uploaded at,
 * and a reader on a phone downloaded the 1080p of it or nothing. A ladder is
 * the same video written two or three more times, smaller, plus a playlist
 * that lets the player pick — the shape PeerTube publishes and the shape every
 * adaptive player expects.
 *
 * A rendition is **one row and one file**, not a directory of segments. HLS
 * normally writes a few hundred little files per video, which would be a few
 * hundred rows and a few hundred objects in the store; `-hls_flags single_file`
 * writes the whole rendition as one fragmented MP4 instead and the playlist
 * addresses each segment as a byte range into it. So the store keeps doing the
 * one thing it is good at — a file per row — and the playlist, a couple of
 * kilobytes of text, is kept in the row beside it.
 *
 * `social_cache_doc.laddered` is the same four-state flag `transcoded` already
 * is, for the same reason: without it the job reads the same videos on every
 * run for the life of the instance.
 */
class Version1000Date20260916000006 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS)) {
			$documents = $schema->getTable(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS);
			if (!$documents->hasColumn('laddered')) {
				$documents->addColumn('laddered', Types::SMALLINT, [
					'notnull' => false,
					'default' => 0,
				]);
			}
		}

		if (!$schema->hasTable(CoreRequestBuilder::TABLE_RENDITIONS)) {
			$table = $schema->createTable(CoreRequestBuilder::TABLE_RENDITIONS);
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			/** the `social_cache_doc.nid` of the video this is a smaller copy of */
			$table->addColumn('doc_nid', Types::BIGINT, [
				'notnull' => false,
				'length' => 11,
				'unsigned' => true,
				'default' => 0,
			]);
			$table->addColumn('height', Types::INTEGER, ['notnull' => false, 'default' => 0]);
			/**
			 * Bits per second, measured from the file rather than asked for:
			 * it is what the master playlist advertises, and a player picks a
			 * rung by comparing it against what the connection is doing.
			 */
			$table->addColumn('bandwidth', Types::INTEGER, ['notnull' => false, 'default' => 0]);
			$table->addColumn('size', Types::BIGINT, [
				'notnull' => false,
				'length' => 20,
				'unsigned' => true,
				'default' => 0,
			]);
			/** where the fragmented MP4 is in the store */
			$table->addColumn('local_copy', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => '']);
			/**
			 * The rendition's own m3u8, as ffmpeg wrote it but with the media
			 * file's name replaced by a placeholder: the URI it has to carry
			 * is a route on this server, which is not known at encode time and
			 * changes if the instance is moved.
			 */
			$table->addColumn('playlist', Types::TEXT, ['notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			// one rung per height per video
			$table->addUniqueIndex(['doc_nid', 'height'], 'social_rend_dh');
		}

		return $schema;
	}
}
