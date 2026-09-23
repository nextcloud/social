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
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * What kind of media a post carries, in a column an index can be put on.
 *
 * The Photos and Videos timelines — and Mastodon's `only_media` on every other
 * one — were answered by searching the stored JSON with `LIKE`:
 * `attachments LIKE '%"type":"video"%'` for a video, and three string
 * comparisons against `''`, `'[]'` and NULL for "has any media". Neither can
 * use an index, and both are applied *after* the join, so the Videos timeline
 * on a ten-million-row table reads every candidate row and then decides.
 *
 * The kind is known when the post is stored, so it is written there. `(kind,
 * nid)` then answers "the newest twenty videos" the way the public timeline
 * answers "the newest twenty posts": a descending range, stopping when it has
 * enough.
 *
 * The values are the four `MediaAttachment` types this app stores plus two of
 * its own: `''` for a post with nothing attached, and `mixed` for one carrying
 * more than one kind — a post with a photograph and a video is both, and a
 * column has to choose. `mixed` is matched by every "has this kind" predicate
 * rather than by none, because a post with a video in it belongs in the Videos
 * timeline whatever else it carries.
 *
 * `null` is "not looked at yet", which is every row written before this: the
 * backfill below fills them in, and until it has, the old `LIKE` predicate is
 * what answers. That is what makes this safe to deploy on a running instance.
 */
class Version1000Date20260917000003 extends SimpleMigrationStep {
	/**
	 * How many posts one backfill statement looks at.
	 *
	 * One placeholder a row plus the kind, so a page of this size is one
	 * statement of 5,001 parameters at worst. The same size as the recipient
	 * backfill's, which had to come down under SQLite's limit of 32,766: this
	 * one was inside it at 20,000, but a batch size chosen a hair under a
	 * ceiling is one that a second bound parameter turns into a failed upgrade.
	 */
	private const BATCH = 5000;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * The columns this step used to add are in `Version1000Date20221118000002`,
	 * which describes the whole schema and runs before this. What is left here
	 * is the half a schema cannot express: the rows.
	 */

	/**
	 * Works out the kind of every post already stored.
	 *
	 * In PHP rather than in SQL, because the answer is "what types does this
	 * JSON list contain", and the three databases this app supports spell JSON
	 * differently enough that the query would be three queries. A page at a
	 * time, so the statement is bounded on a table with ten million rows in it.
	 *
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$stream = '*PREFIX*' . CoreRequestBuilder::TABLE_STREAM;

		$total = (int)$this->connection->executeQuery(
			'SELECT COUNT(*) FROM `' . $stream . '` WHERE `media_kind` IS NULL'
		)->fetchOne();
		if ($total < 1) {
			return;
		}

		$output->info('working out what ' . $total . ' post(s) carry');

		$after = '0';
		$done = 0;
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `nid`, `attachments`, `subtype` FROM `' . $stream . '`'
				. ' WHERE `nid` > ? AND `media_kind` IS NULL ORDER BY `nid` ASC LIMIT ' . self::BATCH,
				[$after]
			)->fetchAll();

			if ($page === []) {
				break;
			}

			$byKind = [];
			foreach ($page as $row) {
				$after = \OCA\Social\Tools\Nid::compare($after, (string)$row['nid']) > 0 ? $after : (string)$row['nid'];
				$kind = Stream::mediaKindOf(
					(string)($row['attachments'] ?? ''), (string)($row['subtype'] ?? '')
				);
				$byKind[$kind][] = (string)$row['nid'];
			}

			// one statement per kind per page rather than one per row
			foreach ($byKind as $kind => $nids) {
				$in = implode(', ', array_fill(0, count($nids), '?'));
				$this->connection->executeStatement(
					'UPDATE `' . $stream . '` SET `media_kind` = ? WHERE `nid` IN (' . $in . ')',
					array_merge([$kind], array_map('strval', $nids))
				);
			}

			$done += count($page);
			if ($done % (self::BATCH * 10) === 0) {
				$output->info('  ' . $done . ' / ' . $total);
			}
		}

		$output->info('  done');
	}
}
