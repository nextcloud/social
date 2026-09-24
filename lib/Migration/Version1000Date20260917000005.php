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
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Whether a post is news, in a column an index can be put on.
 *
 * The News timeline asks two questions of every post: is this an `Article` or
 * a `Page` — the type the blogging servers publish — and does its text carry a
 * link to somewhere else. The first is a string comparison on `subtype`, which
 * is not indexed; the second is a set of regular expressions over the stored
 * markup, which no database can answer at all. Asked on the read, the newest
 * twenty news items on a ten-million-row table would mean reading the table.
 *
 * Both are known when the post is stored, so the answer is written there once.
 * `(news_kind, nid)` then answers "the newest twenty news posts" the way the
 * public timeline answers "the newest twenty posts": a descending range,
 * stopping when it has enough.
 *
 * `null` is "not looked at yet", which is every row written before this. The
 * backfill below fills them in; until it has, those rows are simply not in the
 * News timeline — unlike the media columns, there is no cheap SQL fallback to
 * judge them by, and a News page that is short for one upgrade is better than
 * one that reads ten million rows of markup to be complete. Every *new* post
 * is classified from the moment the column exists, so the page is right at the
 * top from the start, which is the end a reader looks at.
 *
 * @see Stream::newsKindOf()
 */
class Version1000Date20260917000005 extends SimpleMigrationStep {
	/**
	 * How many posts one backfill statement looks at.
	 *
	 * The same size as the `media_kind` backfill's, and for the same reason:
	 * one placeholder a row plus the kind, which stays well inside SQLite's
	 * limit of 32,766 bound parameters.
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
	 * Works out what every post already stored is.
	 *
	 * In PHP, because the question is "does this markup contain a link that is
	 * not a mention or a hashtag", which is not a question SQL is asked. A page
	 * at a time, so the statement is bounded on a table with ten million rows
	 * in it.
	 *
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$stream = '*PREFIX*' . CoreRequestBuilder::TABLE_STREAM;

		$total = (int)$this->connection->executeQuery(
			'SELECT COUNT(*) FROM `' . $stream . '` WHERE `news_kind` IS NULL'
		)->fetchOne();
		if ($total < 1) {
			return;
		}

		$output->info('working out which of ' . $total . ' post(s) are news');

		$after = '0';
		$done = 0;
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `nid`, `content`, `subtype` FROM `' . $stream . '`'
				. ' WHERE `nid` > ? AND `news_kind` IS NULL ORDER BY `nid` ASC LIMIT ' . self::BATCH,
				[$after]
			)->fetchAll();

			if ($page === []) {
				break;
			}

			$byKind = [];
			foreach ($page as $row) {
				$after = \OCA\Social\Tools\Nid::compare($after, (string)$row['nid']) > 0 ? $after : (string)$row['nid'];
				$kind = Stream::newsKindOf(
					(string)($row['content'] ?? ''), (string)($row['subtype'] ?? '')
				);
				$byKind[$kind][] = (string)$row['nid'];
			}

			// one statement per kind per page rather than one per row
			foreach ($byKind as $kind => $nids) {
				$in = implode(', ', array_fill(0, count($nids), '?'));
				$this->connection->executeStatement(
					'UPDATE `' . $stream . '` SET `news_kind` = ? WHERE `nid` IN (' . $in . ')',
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
