<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Nid;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_stream.short_kind`: whether a post is a short video, in a column an
 * index can be put on.
 *
 * `only_short` asks how long a post's video runs and how it is shaped, which
 * is in the attachment's meta inside a JSON text column — nothing a database
 * can be asked on the read, and on a timeline where one post in a thousand is
 * a short, a filter applied to a page after it was chosen comes back empty.
 * So the answer is written with the post (`Stream::shortKindOf()`) and
 * `(short_kind, nid)` reads "the newest twenty shorts" as a descending range.
 *
 * NULL is "not looked at", and only a post that is not a video is left so:
 * the backfill below reads the posts whose `media_kind` is `video`, through
 * `social_s_mk`, and writes `short` or `''` on each. Every other post is not a
 * short either way, and reading all of them to write `''` would be ten million
 * row rewrites for nothing. Run again, it finds no video left unread.
 *
 * @see Stream::shortKindOf()
 */
class Version1000Date20261012500100 extends SimpleMigrationStep {
	/** How many posts one backfill statement looks at. */
	private const BATCH = 2000;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable(CoreRequestBuilder::TABLE_STREAM)) {
			return null;
		}

		$table = $schema->getTable(CoreRequestBuilder::TABLE_STREAM);
		$changed = false;

		if (!$table->hasColumn('short_kind')) {
			$table->addColumn('short_kind', Types::STRING, ['default' => null, 'length' => 7, 'notnull' => false]);
			$changed = true;
		}

		if (!$table->hasIndex('social_s_sk')) {
			$table->addIndex(['short_kind', 'nid'], 'social_s_sk');
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	/**
	 * Works out which of the videos already stored are shorts.
	 *
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$stream = '*PREFIX*' . CoreRequestBuilder::TABLE_STREAM;

		$after = '0';
		$done = 0;
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `nid`, `attachments` FROM `' . $stream . '`'
				. ' WHERE `media_kind` = ? AND `nid` > ? AND `short_kind` IS NULL'
				. ' ORDER BY `nid` ASC LIMIT ' . self::BATCH,
				['video', $after]
			)->fetchAll();

			if ($page === []) {
				break;
			}

			$byKind = [];
			foreach ($page as $row) {
				$after = Nid::compare($after, (string)$row['nid']) > 0 ? $after : (string)$row['nid'];
				$byKind[Stream::shortKindOf((string)($row['attachments'] ?? ''))][] = (string)$row['nid'];
			}

			foreach ($byKind as $kind => $nids) {
				$in = implode(', ', array_fill(0, count($nids), '?'));
				$this->connection->executeStatement(
					'UPDATE `' . $stream . '` SET `short_kind` = ? WHERE `nid` IN (' . $in . ')',
					array_merge([$kind], $nids)
				);
			}

			$done += count($page);
		}

		if ($done > 0) {
			$output->info('looked at ' . $done . ' video post(s) for shorts');
		}
	}
}
