<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\FollowedTagsRequest;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_stream_tag.hashtag` in the one form a hashtag is compared in.
 *
 * A post's tags used to be stored as their author wrote them, so the hashtag
 * timeline and the followed-tags join compared `LOWER(st.hashtag)` — and no
 * index can answer a comparison over a function of the column: the tag was a
 * filter applied to every tag row of every candidate post, walking the stream
 * newest-first until twenty matched. The rows are now written normalised
 * (`FollowedTagsRequest::normalise()`, the form `social_hashtag` and
 * `social_followed_tag` already hold) and compared as they stand; this step
 * brings the rows written before that into the same form.
 *
 * Keyset-paged on the primary key, so the walk is one pass however many rows
 * need it. Only the rows whose tag is not lowercase are read on MySQL and
 * PostgreSQL, whose `LOWER()` folds all of Unicode; SQLite's folds ASCII only,
 * so there every row is read and the comparison is made here.
 *
 * Two rows of one post can become the same tag — `#Nextcloud` and `#nextcloud`
 * on one post — and the unique index on (stream_id, hashtag) would refuse the
 * second. The one that would collide is deleted instead: the post keeps its
 * tag, once.
 */
class Version1000Date20260925000001 extends SimpleMigrationStep {
	/**
	 * Rows per page. The rewrite binds three parameters a row — `WHEN`,
	 * `THEN` and the `IN` — so 5,000 stays inside SQLite's 32,766.
	 */
	public const BATCH = 5000;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// `*PREFIX*` is what the connection rewrites; there is no public API
		// that hands the prefix over as a string
		$table = '`*PREFIX*' . CoreRequestBuilder::TABLE_STREAM_TAGS . '`';
		$folds = ($this->connection->getDatabaseProvider() !== IDBConnection::PLATFORM_SQLITE)
			? ' AND `hashtag` <> LOWER(`hashtag`)'
			: '';

		$after = 0;
		$rewritten = 0;
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `id`, `stream_id`, `hashtag` FROM ' . $table
				. ' WHERE `id` > ?' . $folds . ' ORDER BY `id` ASC LIMIT ' . self::BATCH,
				[(string)$after]
			)->fetchAll();
			if ($page === []) {
				break;
			}
			$after = (int)$page[count($page) - 1]['id'];

			$changing = array_values(array_filter(
				$page,
				static fn (array $row): bool => FollowedTagsRequest::normalise((string)$row['hashtag']) !== (string)$row['hashtag']
			));
			if ($changing !== []) {
				$streams = array_values(array_unique(array_map(
					static fn (array $row): string => (string)$row['stream_id'], $changing
				)));
				$siblings = $this->connection->executeQuery(
					'SELECT `stream_id`, `hashtag` FROM ' . $table
					. ' WHERE `stream_id` IN (' . implode(', ', array_fill(0, count($streams), '?')) . ')',
					$streams
				)->fetchAll();

				$plan = self::plan($changing, $siblings);
				$this->apply($table, $plan['update'], $plan['delete']);
				$rewritten += count($changing);
				$output->info('normalised ' . $rewritten . ' hashtag row(s)');
			}

			if (count($page) < self::BATCH) {
				break;
			}
		}
	}

	/**
	 * What to do with the rows of one page whose tag is not normalised.
	 *
	 * A row becomes its normalised tag unless another row of the same post
	 * already holds that tag — one that is normalised, or one earlier in this
	 * page that is about to be — and is deleted then. A tag that normalises to
	 * nothing is deleted too: no post could be found by it.
	 *
	 * @param list<array{id: int|string, stream_id: string, hashtag: string}> $changing in id order
	 * @param list<array{stream_id: string, hashtag: string}> $siblings every tag row of those posts
	 *
	 * @return array{update: array<int, string>, delete: list<int>}
	 */
	public static function plan(array $changing, array $siblings): array {
		$taken = [];
		foreach ($siblings as $row) {
			$tag = (string)$row['hashtag'];
			if (FollowedTagsRequest::normalise($tag) === $tag) {
				$taken[$row['stream_id'] . "\0" . $tag] = true;
			}
		}

		$update = [];
		$delete = [];
		foreach ($changing as $row) {
			$tag = FollowedTagsRequest::normalise((string)$row['hashtag']);
			$key = $row['stream_id'] . "\0" . $tag;
			if ($tag === '' || isset($taken[$key])) {
				$delete[] = (int)$row['id'];
				continue;
			}

			$taken[$key] = true;
			$update[(int)$row['id']] = $tag;
		}

		return ['update' => $update, 'delete' => $delete];
	}

	/**
	 * @param array<int, string> $update id => tag
	 * @param list<int> $delete
	 */
	private function apply(string $table, array $update, array $delete): void {
		if ($delete !== []) {
			$this->connection->executeStatement(
				'DELETE FROM ' . $table . ' WHERE `id` IN (' . implode(', ', array_fill(0, count($delete), '?')) . ')',
				$delete
			);
		}

		if ($update === []) {
			return;
		}

		$cases = '';
		$values = [];
		foreach ($update as $id => $tag) {
			$cases .= ' WHEN ? THEN ?';
			$values[] = $id;
			$values[] = $tag;
		}

		$this->connection->executeStatement(
			'UPDATE ' . $table . ' SET `hashtag` = CASE `id`' . $cases . ' END'
			. ' WHERE `id` IN (' . implode(', ', array_fill(0, count($update), '?')) . ')',
			array_merge($values, array_keys($update))
		);
	}
}
