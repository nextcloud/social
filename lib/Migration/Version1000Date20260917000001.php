<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Service\ConfigService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The post's own sort key, on the row that says who received it.
 *
 * This is the column that decides whether a home timeline works on a large
 * instance. Without it the page query has no way to order by anything on the
 * recipient row, so the planner drives from the viewer's follows, fetches
 * **every recipient row every followed account has ever produced**, joins each
 * one to `social_stream` for its `nid`, puts the lot in a temporary table and
 * sorts it — to keep twenty. `EXPLAIN` said so in as many words:
 * `Using temporary; Using filesort`. The cost of one page load is therefore
 * Σ(all posts of everyone you follow), and a client asks for it every thirty
 * seconds.
 *
 * With `nid` here and an index on `(actor_id, type, nid)`, the same page is a
 * descending index range per followed collection, merged, stopping after
 * twenty — index-only, and bounded by the page size rather than by the
 * instance's history.
 *
 * A denormalised copy is the right shape for it. The alternative, an index on
 * `social_stream` the recipient join could drive, cannot exist: the sort key
 * and the filter are on different tables, and no index spans two. What it
 * costs is that a post's nid is written in two places — and a nid never
 * changes after the row is written, which is what makes the copy safe: there
 * is no update path to keep in step, only an insert.
 *
 * Existing rows are backfilled in `postSchemaChange`, in batches, because on an
 * instance with ten million posts this is twenty million rows and a single
 * `UPDATE … JOIN` would hold one transaction over all of them. A row that has
 * not been backfilled yet carries `0`, which sorts before everything and is
 * therefore invisible at the head of a timeline rather than wrong — and
 * `StreamRequest` keeps the old predicate available for exactly that window.
 */
class Version1000Date20260917000001 extends SimpleMigrationStep {
	/**
	 * How many recipient rows one backfill statement carries.
	 *
	 * Three bound parameters a row — the `WHEN`, the `THEN` and the `IN` — so
	 * this is the number of placeholders in one statement divided by three.
	 * **SQLite allows 32,766**, and at 20,000 rows this statement asked it for
	 * 60,000: an upgrade on any SQLite instance with more than about eleven
	 * thousand posts failed with "too many SQL variables" and left the backfill
	 * half done. PostgreSQL's wire protocol allows 65,535, so it fitted there
	 * with little to spare. 5,000 is 15,000 placeholders, comfortably inside
	 * the smallest of the three ceilings and still four times fewer round trips
	 * than a statement per row.
	 */
	private const BATCH = 5000;

	public function __construct(
		private IDBConnection $connection,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(CoreRequestBuilder::TABLE_STREAM_DEST)) {
			return $schema;
		}

		$table = $schema->getTable(CoreRequestBuilder::TABLE_STREAM_DEST);

		if (!$table->hasColumn('nid')) {
			$table->addColumn('nid', Types::BIGINT, [
				'notnull' => false,
				'length' => 11,
				'default' => 0,
			]);
		}

		// the whole point: `WHERE actor_id = ? AND type = ? ORDER BY nid DESC`
		// answered from the index, without touching a row
		if (!$table->hasIndex('social_sd_atn')) {
			$table->addIndex(['actor_id', 'type', 'nid'], 'social_sd_atn');
		}

		return $schema;
	}

	/**
	 * Fills in the nids of the rows that already exist.
	 *
	 * Keyed off `stream_id` → `social_stream.id_prim`, which is the join the
	 * page query used to make per row and now makes once, here. Batched on the
	 * stream side rather than the recipient side so each statement is a range
	 * of posts: `social_stream` is ordered by `nid` and a range of it is
	 * contiguous, where a range of recipient rows is scattered across the
	 * table.
	 *
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// `*PREFIX*` is what the connection rewrites; there is no public API
		// that hands the prefix over as a string
		$dest = '*PREFIX*' . CoreRequestBuilder::TABLE_STREAM_DEST;
		$stream = '*PREFIX*' . CoreRequestBuilder::TABLE_STREAM;

		$total = (int)$this->connection->executeQuery(
			'SELECT COUNT(*) FROM `' . $stream . '`'
		)->fetchOne();
		if ($total < 1) {
			// nothing to fill in, which is the same as having filled it in
			$this->markFilled();

			return;
		}

		$output->info('filling in the recipient rows\' sort key for ' . $total . ' post(s)');

		$after = 0;
		$done = 0;
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `nid`, `id_prim` FROM `' . $stream . '` WHERE `nid` > ? ORDER BY `nid` ASC LIMIT '
				. self::BATCH,
				[(string)$after]
			)->fetchAll();

			if ($page === []) {
				break;
			}

			// one statement per page, as a CASE over the page's prims: an
			// UPDATE per post would be twenty million round trips
			$prims = [];
			$cases = '';
			$values = [];
			foreach ($page as $row) {
				$after = max($after, (int)$row['nid']);
				$prims[] = (string)$row['id_prim'];
				$cases .= ' WHEN ? THEN ?';
				$values[] = (string)$row['id_prim'];
				$values[] = (string)(int)$row['nid'];
			}

			$in = implode(', ', array_fill(0, count($prims), '?'));
			$this->connection->executeStatement(
				'UPDATE `' . $dest . '` SET `nid` = CASE `stream_id`' . $cases . ' END'
				. ' WHERE `stream_id` IN (' . $in . ')',
				array_merge($values, $prims)
			);

			$done += count($page);
			if ($done % (self::BATCH * 10) === 0) {
				$output->info('  ' . $done . ' / ' . $total);
			}
		}

		$this->markFilled();
		$output->info('  done');
	}

	/**
	 * Records that every recipient row now carries its post's nid.
	 *
	 * What the read path consults. It cannot ask the table instead: there is
	 * no index that answers "is any nid still zero", so the question is a full
	 * scan of the largest table this app has — measured at 427 ms on 800,000
	 * rows, which on every request would have cost more than the query the
	 * flag exists to enable.
	 */
	private function markFilled(): void {
		$this->appConfig->setValueString('social', ConfigService::SOCIAL_DEST_NID_FILLED, '1');
	}
}
