<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Remote statuses stored before visibility estimation landed carry an empty
 * visibility, so clients cannot tell a public post from a direct message.
 * This backfills them once with the same addressing heuristic used for new
 * arrivals: as:Public in `to` is public, in `cc` unlisted, the author's
 * followers collection followers-only, anything else direct. Rows with a
 * visibility are never touched, so re-runs are no-ops.
 */
class BackfillRemoteVisibility implements IRepairStep {
	private const CHUNK = 1000;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	public function getName(): string {
		return 'Backfill the visibility of remote statuses stored before estimation landed';
	}

	public function run(IOutput $output): void {
		$public = $this->bulkUpdatePublicAndUnlisted();
		[$followers, $direct] = $this->classifyRemainder();

		if ($public + $followers + $direct > 0) {
			$output->info(sprintf(
				'visibility backfilled: %d public/unlisted, %d followers, %d direct',
				$public, $followers, $direct
			));
		}
	}

	/**
	 * The two cases decidable without knowing the author: as:Public in the
	 * `to` addressing is public, in `cc` unlisted. Set-based, one query each.
	 */
	private function bulkUpdatePublicAndUnlisted(): int {
		$count = 0;
		foreach ([
			Stream::TYPE_PUBLIC => ['to', 'to_array'],
			Stream::TYPE_UNLISTED => ['cc'],
		] as $visibility => $fields) {
			$qb = $this->connection->getQueryBuilder();
			$qb->update(CoreRequestBuilder::TABLE_STREAM)
				->set('visibility', $qb->createNamedParameter($visibility))
				->where($qb->expr()->emptyString('visibility'))
				->andWhere($qb->expr()->eq('local', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

			$orX = $qb->expr()->orX();
			foreach ($fields as $field) {
				if ($field === 'to') {
					$orX->add($qb->expr()->eq($field, $qb->createNamedParameter(ACore::CONTEXT_PUBLIC)));
				} else {
					$orX->add($qb->expr()->like(
						$field,
						$qb->createNamedParameter('%"' . $this->connection->escapeLikeParameter(ACore::CONTEXT_PUBLIC) . '"%')
					));
				}
			}
			$qb->andWhere($orX);

			$count += $qb->executeStatement();
		}

		return $count;
	}

	/**
	 * What is left is followers-only or direct, which needs the author's
	 * followers collection: processed in chunks, updated in batches.
	 *
	 * @return array{int, int} [followers, direct]
	 */
	private function classifyRemainder(): array {
		$followersTotal = 0;
		$directTotal = 0;

		while (true) {
			$qb = $this->connection->getQueryBuilder();
			$qb->select('id_prim', 'to', 'to_array', 'cc', 'attributed_to')
				->from(CoreRequestBuilder::TABLE_STREAM)
				->where($qb->expr()->emptyString('visibility'))
				->andWhere($qb->expr()->eq('local', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->setMaxResults(self::CHUNK);

			$cursor = $qb->executeQuery();
			$rows = $cursor->fetchAll();
			$cursor->closeCursor();
			if ($rows === []) {
				break;
			}

			$followersOf = $this->followersCollectionsOf(
				array_values(array_unique(array_column($rows, 'attributed_to')))
			);

			$followers = [];
			$direct = [];
			foreach ($rows as $row) {
				$recipients = array_merge(
					[(string)$row['to']],
					json_decode((string)$row['to_array'], true) ?: [],
					json_decode((string)$row['cc'], true) ?: []
				);
				$collection = $followersOf[(string)$row['attributed_to']] ?? '';
				if ($collection !== '' && in_array($collection, $recipients, true)) {
					$followers[] = (string)$row['id_prim'];
				} else {
					$direct[] = (string)$row['id_prim'];
				}
			}

			$followersTotal += $this->updateByPrims($followers, Stream::TYPE_FOLLOWERS);
			$directTotal += $this->updateByPrims($direct, Stream::TYPE_DIRECT);
		}

		return [$followersTotal, $directTotal];
	}

	/**
	 * @param string[] $actorIds
	 *
	 * @return array<string, string> actor id => followers collection url
	 */
	private function followersCollectionsOf(array $actorIds): array {
		if ($actorIds === []) {
			return [];
		}

		$collections = [];
		foreach (array_chunk($actorIds, 500) as $chunk) {
			$qb = $this->connection->getQueryBuilder();
			$qb->select('id', 'followers')
				->from(CoreRequestBuilder::TABLE_CACHE_ACTORS)
				->where($qb->expr()->in('id_prim', $qb->createNamedParameter(
					array_map(fn (string $id): string => md5($id), $chunk),
					IQueryBuilder::PARAM_STR_ARRAY
				)));

			$cursor = $qb->executeQuery();
			while ($row = $cursor->fetch()) {
				$collections[(string)$row['id']] = (string)$row['followers'];
			}
			$cursor->closeCursor();
		}

		return $collections;
	}

	/**
	 * @param string[] $prims
	 */
	private function updateByPrims(array $prims, string $visibility): int {
		$count = 0;
		foreach (array_chunk($prims, 500) as $chunk) {
			$qb = $this->connection->getQueryBuilder();
			$qb->update(CoreRequestBuilder::TABLE_STREAM)
				->set('visibility', $qb->createNamedParameter($visibility))
				->where($qb->expr()->in('id_prim', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($qb->expr()->emptyString('visibility'));

			$count += $qb->executeStatement();
		}

		return $count;
	}
}
