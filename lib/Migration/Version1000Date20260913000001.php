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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Two index corrections, one of them a correctness fix.
 *
 * **`social_follow` was not unique on the pair it is about.** Both of its
 * unique indexes lead with `accepted` — `afoa` on
 * `(accepted, follow_id_prim, object_id_prim, actor_id_prim)` and `aoa` on
 * `(accepted, object_id_prim, actor_id_prim)` — so "A follows B" could exist
 * twice, once pending and once accepted, and nothing in the schema said
 * otherwise. A follow request accepted while a second request was in flight
 * leaves exactly that. The boolean is dropped from both, which is what makes
 * the pair unique; the rows that were only distinct *because* of it are
 * removed first, keeping the accepted one, since a follow that was accepted is
 * the one both servers believe in.
 *
 * Reads do not lose anything: nothing looks a follow up by `accepted` first —
 * `social_f_aa (actor_id_prim, accepted)` is what answers "the accounts this
 * viewer follows", and it is untouched.
 *
 * **`social_stream_dest.ts (type, subtype)` earns nothing.** Its two columns
 * hold three and three values across the second-largest table in the app, and
 * every query that filters on either also gives `stream_id` or `actor_id`,
 * which `sat` and `social_sd_at` lead with. It was pure write cost: measured on
 * an instance with 65,000 recipient rows, that table carried 27.7 MB of index
 * over 8 MB of data.
 */
class Version1000Date20260913000001 extends SimpleMigrationStep {
	/** Duplicate pairs read per round trip. */
	private const CHUNK = 500;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * The duplicate pairs have to go before the unique index can exist: a
	 * `changeSchema` that adds it over rows that violate it fails the upgrade.
	 */
	#[\Override]
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable(CoreRequestBuilder::TABLE_FOLLOWS)) {
			return;
		}

		$removed = 0;
		while (true) {
			$pairs = $this->duplicatePairs();
			if ($pairs === []) {
				break;
			}

			foreach ($pairs as $pair) {
				$removed += $this->keepOne((string)$pair['object_id_prim'], (string)$pair['actor_id_prim']);
			}
		}

		if ($removed > 0) {
			$output->info(sprintf(
				'removed %d duplicate follow row(s): the same pair existed both pending and accepted',
				$removed
			));
		}
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(CoreRequestBuilder::TABLE_FOLLOWS)) {
			$table = $schema->getTable(CoreRequestBuilder::TABLE_FOLLOWS);

			if ($table->hasIndex('afoa')) {
				$table->dropIndex('afoa');
			}
			if ($table->hasIndex('aoa')) {
				$table->dropIndex('aoa');
			}
			if (!$table->hasIndex('social_f_foa')) {
				$table->addUniqueIndex(
					['follow_id_prim', 'object_id_prim', 'actor_id_prim'], 'social_f_foa'
				);
			}
			// the pair the table is about, unique whatever the follow's state
			if (!$table->hasIndex('social_f_oa_u')) {
				$table->addUniqueIndex(['object_id_prim', 'actor_id_prim'], 'social_f_oa_u');
			}
			// and the non-unique one it replaces, which led with the same two
			if ($table->hasIndex('social_f_oa')) {
				$table->dropIndex('social_f_oa');
			}
		}

		if ($schema->hasTable(CoreRequestBuilder::TABLE_STREAM_DEST)) {
			$table = $schema->getTable(CoreRequestBuilder::TABLE_STREAM_DEST);
			if ($table->hasIndex('ts')) {
				$table->dropIndex('ts');
			}
		}

		return $schema;
	}

	/**
	 * Pairs that exist more than once — which only the `accepted` column in
	 * the old indexes allowed.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function duplicatePairs(): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('object_id_prim', 'actor_id_prim')
			->from(CoreRequestBuilder::TABLE_FOLLOWS)
			->groupBy('object_id_prim', 'actor_id_prim')
			->having($qb->expr()->gt($qb->func()->count('*'), $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->setMaxResults(self::CHUNK);

		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * Keeps one row for the pair — the accepted one where there is one — and
	 * deletes the rest.
	 *
	 * @return int how many were deleted
	 */
	private function keepOne(string $objectPrim, string $actorPrim): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id_prim', 'accepted')
			->from(CoreRequestBuilder::TABLE_FOLLOWS)
			->where($qb->expr()->eq('object_id_prim', $qb->createNamedParameter($objectPrim)))
			->andWhere($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($actorPrim)))
			->orderBy('accepted', 'desc')
			->addOrderBy('id_prim', 'asc');

		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		$deleted = 0;
		foreach (array_slice($rows, 1) as $row) {
			$delete = $this->connection->getQueryBuilder();
			$delete->delete(CoreRequestBuilder::TABLE_FOLLOWS)
				->where($delete->expr()->eq('id_prim', $delete->createNamedParameter((string)$row['id_prim'])));
			$deleted += $delete->executeStatement();
		}

		return $deleted;
	}
}
