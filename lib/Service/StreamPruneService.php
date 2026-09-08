<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use DateTime;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Retention for federated content: remote statuses older than the configured
 * number of days are deleted together with their dest/action/tag rows and
 * cached attachments — unless someone local still cares about them.
 *
 * A remote status is protected when:
 *  - a local user interacted with it (like/boost/reply/bookmark flags, or a
 *    Like/Announce action row),
 *  - its author is followed by a local user,
 *  - a local status replies to it (threads under local posts stay readable),
 *  - a local stream row references it as its object (a local boost),
 *  - it is a direct message.
 *
 * Local content is never touched. Disabled by default (`retention_days` = 0).
 */
class StreamPruneService {
	public const CHUNK = 500;

	public function __construct(
		private IDBConnection $connection,
		private ConfigService $configService,
		private CacheDocumentService $cacheDocumentService,
		private LoggerInterface $logger,
	) {
	}

	public function getRetentionDays(): int {
		return (int)$this->configService->getAppValue(ConfigService::SOCIAL_RETENTION_DAYS);
	}

	/**
	 * @return array{streams: int, documents: int} what was (or would be) removed
	 */
	public function prune(?int $days = null, bool $dryRun = false, int $max = 0): array {
		$days ??= $this->getRetentionDays();
		if ($days <= 0) {
			return ['streams' => 0, 'documents' => 0];
		}

		$cutoff = new DateTime($days . ' days ago');
		if ($dryRun) {
			return ['streams' => $this->countPrunable($cutoff), 'documents' => 0];
		}

		$streams = 0;
		$documents = 0;
		while (true) {
			$limit = self::CHUNK;
			if ($max > 0) {
				$limit = min($limit, $max - $streams);
				if ($limit <= 0) {
					break;
				}
			}

			$prims = $this->selectPrunable($cutoff, $limit);
			if ($prims === []) {
				break;
			}

			$streams += count($prims);
			$documents += $this->deleteDocumentsOf($prims);
			$this->deleteRelated($prims);
			$this->deleteStreams($prims);
		}

		if ($streams > 0) {
			$this->logger->info('stream retention pruned rows', [
				'days' => $days, 'streams' => $streams, 'documents' => $documents,
			]);
		}

		return ['streams' => $streams, 'documents' => $documents];
	}

	private function countPrunable(DateTime $cutoff): int {
		$qb = $this->prunableQuery($cutoff);
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count');

		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['count'] ?? 0);
	}

	/**
	 * @return string[] id_prim of prunable remote streams, oldest first
	 */
	private function selectPrunable(DateTime $cutoff, int $limit): array {
		$qb = $this->prunableQuery($cutoff);
		$qb->select('s.id_prim')
			->orderBy('s.creation', 'asc')
			->setMaxResults($limit);

		$cursor = $qb->executeQuery();
		$prims = array_map(static fn (array $row): string => (string)$row['id_prim'], $cursor->fetchAll());
		$cursor->closeCursor();

		return $prims;
	}

	/**
	 * The conditions that make a remote stream prunable; see the class doc for
	 * the protection rules.
	 */
	private function prunableQuery(DateTime $cutoff): IQueryBuilder {
		$qb = $this->connection->getQueryBuilder();
		$expr = $qb->expr();

		$qb->from(CoreRequestBuilder::TABLE_STREAM, 's')
			->where($expr->eq('s.local', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->in('s.type', $qb->createNamedParameter(
				[Note::TYPE, Announce::TYPE], IQueryBuilder::PARAM_STR_ARRAY
			)))
			->andWhere($expr->lt('s.creation', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE)))
			->andWhere($expr->neq('s.visibility', $qb->createNamedParameter(Stream::TYPE_DIRECT)));

		// a local interaction flag on the status
		$act = $this->connection->getQueryBuilder();
		$act->select($act->createFunction('1'))
			->from(CoreRequestBuilder::TABLE_STREAM_ACTIONS, 'sa')
			->where('sa.stream_id_prim = s.id_prim')
			// quoted literals: these are boolean columns on PostgreSQL, ints elsewhere
			->andWhere("(sa.liked = '1' OR sa.boosted = '1' OR sa.replied = '1' OR sa.bookmarked = '1')");
		$qb->andWhere('NOT EXISTS (' . $act->getSQL() . ')');

		// a Like/Announce action row pointing at the status
		$action = $this->connection->getQueryBuilder();
		$action->select($action->createFunction('1'))
			->from(CoreRequestBuilder::TABLE_ACTIONS, 'a')
			->where('a.object_id_prim = s.id_prim');
		$qb->andWhere('NOT EXISTS (' . $action->getSQL() . ')');

		// its author is followed by a local user
		$follow = $this->connection->getQueryBuilder();
		$follow->select($follow->createFunction('1'))
			->from(CoreRequestBuilder::TABLE_FOLLOWS, 'f')
			->where('f.object_id_prim = s.attributed_to_prim')
			->andWhere("f.accepted = '1'");
		$qb->andWhere('NOT EXISTS (' . $follow->getSQL() . ')');

		// a local status replies to it, or a local stream (a boost) references it
		$local = $this->connection->getQueryBuilder();
		$local->select($local->createFunction('1'))
			->from(CoreRequestBuilder::TABLE_STREAM, 's2')
			->where("s2.local = '1'")
			->andWhere('(s2.in_reply_to_prim = s.id_prim OR s2.object_id_prim = s.id_prim)');
		$qb->andWhere('NOT EXISTS (' . $local->getSQL() . ')');

		return $qb;
	}

	/**
	 * @param string[] $prims
	 */
	private function deleteDocumentsOf(array $prims): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id_prim', 'local_copy', 'resized_copy')
			->from(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS)
			->where($qb->expr()->in('parent_id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)));

		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();
		if ($rows === []) {
			return 0;
		}

		foreach ($rows as $row) {
			$this->cacheDocumentService->removeFromCache((string)$row['local_copy']);
			$this->cacheDocumentService->removeFromCache((string)$row['resized_copy']);
		}

		$delete = $this->connection->getQueryBuilder();
		$delete->delete(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS)
			->where($delete->expr()->in('id_prim', $delete->createNamedParameter(
				array_map(static fn (array $row): string => (string)$row['id_prim'], $rows),
				IQueryBuilder::PARAM_STR_ARRAY
			)));

		return $delete->executeStatement();
	}

	/**
	 * dest, action-flag and tag rows key their stream by its prim
	 *
	 * @param string[] $prims
	 */
	private function deleteRelated(array $prims): void {
		foreach ([
			[CoreRequestBuilder::TABLE_STREAM_DEST, 'stream_id'],
			[CoreRequestBuilder::TABLE_STREAM_ACTIONS, 'stream_id_prim'],
			[CoreRequestBuilder::TABLE_STREAM_TAGS, 'stream_id'],
			[CoreRequestBuilder::TABLE_STREAM_CARDS, 'stream_id_prim'],
		] as [$table, $field]) {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in($field, $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	/**
	 * @param string[] $prims
	 */
	private function deleteStreams(array $prims): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete(CoreRequestBuilder::TABLE_STREAM)
			->where($qb->expr()->in('id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)));
		$qb->executeStatement();
	}
}
