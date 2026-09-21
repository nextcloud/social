<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_cache_actors.host`: the server an account is on, as a column.
 *
 * Four things ask "which servers does this instance know, and how many
 * accounts does it hold on each" — the instance statistics, the network
 * figures, the directory's federation sources and the Pixelfed admin API —
 * and every one of them read **every cached actor row** and split the handle
 * in PHP, because there was nothing to group on. On an instance that has been
 * federating for a year that is hundreds of thousands of rows fetched to
 * produce a list of a few thousand hosts, on a page an administrator opens.
 *
 * The host is derived from the handle and never changes for a row: an account
 * that moves servers is a different account with a different id. That is what
 * makes the copy safe — there is no update path to keep in step, only an
 * insert.
 *
 * Existing rows are backfilled here in batches. A row that has not been
 * reached yet carries `''`, which the grouping skips: the count is briefly low
 * rather than wrong, and one pass of the upgrade fixes it.
 */
class Version1000Date20260920000002 extends SimpleMigrationStep {
	/**
	 * How many rows one backfill statement carries.
	 *
	 * Two bound parameters a row — the `WHEN` and the `IN` — plus one `THEN`,
	 * so 5,000 rows is 15,000 placeholders: inside SQLite's 32,766 ceiling,
	 * which is the smallest of the three, with room to spare. See
	 * `Version1000Date20260917000001`, where a larger batch failed on SQLite.
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

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// `*PREFIX*` is what the connection rewrites; there is no public API
		// that hands the prefix over as a string
		$table = '*PREFIX*' . CoreRequestBuilder::TABLE_CACHE_ACTORS;

		$total = (int)$this->connection->executeQuery(
			'SELECT COUNT(*) FROM `' . $table . '` WHERE `host` = \'\' OR `host` IS NULL'
		)->fetchOne();
		if ($total < 1) {
			return;
		}

		$output->info('filling in the server of ' . $total . ' cached account(s)');

		$done = 0;
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `id_prim`, `account` FROM `' . $table . '`'
				. ' WHERE (`host` = \'\' OR `host` IS NULL) LIMIT ' . self::BATCH
			)->fetchAll();

			if ($page === []) {
				break;
			}

			$prims = [];
			$cases = '';
			$values = [];
			foreach ($page as $row) {
				$prims[] = (string)$row['id_prim'];
				$cases .= ' WHEN ? THEN ?';
				$values[] = (string)$row['id_prim'];
				$values[] = self::hostOf((string)$row['account']);
			}

			$in = implode(', ', array_fill(0, count($prims), '?'));
			$this->connection->executeStatement(
				'UPDATE `' . $table . '` SET `host` = CASE `id_prim`' . $cases . ' END'
				. ' WHERE `id_prim` IN (' . $in . ')',
				array_merge($values, $prims)
			);

			$done += count($page);
			$output->info('… ' . $done . '/' . $total);

			if (count($page) < self::BATCH) {
				break;
			}
		}
	}

	/**
	 * The server out of a handle.
	 *
	 * A handle with no `@` in it is a local account, whose host is this one —
	 * and which this column is not about. It is left empty, which is what the
	 * grouping skips.
	 */
	public static function hostOf(string $account): string {
		$at = strrpos($account, '@');
		if ($at === false) {
			return '';
		}

		return strtolower(substr($account, $at + 1));
	}
}
