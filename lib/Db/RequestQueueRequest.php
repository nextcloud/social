<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Model\RequestQueue;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Class RequestQueueRequest
 *
 * @package OCA\Social\Db
 */
class RequestQueueRequest extends RequestQueueRequestBuilder {
	/** How many standby requests a single cron pass hydrates. */
	public const STANDBY_BATCH = 200;

	/**
	 * Create a new Queue in the database.
	 *
	 * @param RequestQueue[] $queues
	 *
	 * @throws Exception
	 */
	public function multiple(array $queues): void {
		foreach ($queues as $queue) {
			$this->create($queue);
		}
	}

	/**
	 * Create a new Queue in the database.
	 *
	 * @throws Exception
	 */
	public function create(RequestQueue $queue): void {
		$qb = $this->getRequestQueueInsertSql();
		$qb->setValue('token', $qb->createNamedParameter($queue->getToken()))
			->setValue('author', $qb->createNamedParameter($queue->getAuthor()))
			->setValue('author_prim', $qb->createNamedParameter($qb->prim($queue->getAuthor())))
			->setValue('activity', $qb->createNamedParameter($queue->getActivity()))
			->setValue(
				'instance', $qb->createNamedParameter(
					json_encode($queue->getInstance(), JSON_UNESCAPED_SLASHES)
				)
			)
			->setValue('priority', $qb->createNamedParameter($queue->getPriority()))
			->setValue('status', $qb->createNamedParameter($queue->getStatus()))
			->setValue('tries', $qb->createNamedParameter($queue->getTries()));
		$qb->executeStatement();
	}

	/**
	 * Return Queue from database based on the status=0
	 *
	 * @return list<RequestQueue>
	 * @throws Exception
	 */
	public function getStandby(): array {
		$qb = $this->getRequestQueueSelectSql();
		$this->limitToStatus($qb, RequestQueue::STATUS_STANDBY);
		$qb->orderBy('id', 'asc');
		$qb->setMaxResults(self::STANDBY_BATCH);

		$requests = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$requests[] = $this->parseRequestQueueSelectSql($data);
		}
		$cursor->closeCursor();

		return $requests;
	}

	/**
	 * How many requests sit in the queue in each state.
	 *
	 * @return array<int, int> status => count
	 * @throws Exception
	 */
	public function countByStatus(): array {
		$qb = $this->getQueryBuilder();
		$qb->select('status')
			->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_REQUEST_QUEUE)
			->groupBy('status');

		$counts = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$counts[(int)$data['status']] = (int)$data['total'];
		}
		$cursor->closeCursor();

		return $counts;
	}

	/**
	 * The requests that have already failed at least `$minTries` times, worst
	 * first — the ones on their way to being abandoned.
	 *
	 * @return list<RequestQueue>
	 * @throws Exception
	 */
	public function getFailing(int $minTries = 1, int $limit = 500): array {
		$qb = $this->getRequestQueueSelectSql();
		$qb->andWhere($qb->expr()->gte('tries', $qb->createNamedParameter($minTries, IQueryBuilder::PARAM_INT)));
		$qb->orderBy('tries', 'desc');
		$qb->setMaxResults($limit);

		$requests = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$requests[] = $this->parseRequestQueueSelectSql($data);
		}
		$cursor->closeCursor();

		return $requests;
	}

	/**
	 * Return Queue from database based on the token
	 *
	 * @return list<RequestQueue>
	 * @throws Exception
	 */
	public function getFromToken(string $token, int $status = -1): array {
		$qb = $this->getRequestQueueSelectSql();
		$qb->limitToToken($token);

		if ($status > -1) {
			$this->limitToStatus($qb, $status);
		}

		$qb->orderBy('priority', 'desc');

		$requests = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$requests[] = $this->parseRequestQueueSelectSql($data);
		}
		$cursor->closeCursor();

		return $requests;
	}

	/**
	 * @throws QueueStatusException|Exception
	 */
	public function setAsRunning(RequestQueue &$queue): void {
		$qb = $this->getRequestQueueUpdateSql();
		$qb->set('status', $qb->createNamedParameter(RequestQueue::STATUS_RUNNING))
			->set(
				'last',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		$this->limitToId($qb, $queue->getId());
		$this->limitToStatus($qb, RequestQueue::STATUS_STANDBY);

		$count = $qb->executeStatement();

		if ($count === 0) {
			throw new QueueStatusException();
		}

		$queue->setStatus(RequestQueue::STATUS_RUNNING);
	}

	/**
	 * @throws QueueStatusException|Exception
	 */
	public function setAsSuccess(RequestQueue &$queue): void {
		$qb = $this->getRequestQueueUpdateSql();
		$qb->set('status', $qb->createNamedParameter(RequestQueue::STATUS_SUCCESS));
		$this->limitToId($qb, $queue->getId());
		$this->limitToStatus($qb, RequestQueue::STATUS_RUNNING);

		$count = $qb->executeStatement();

		if ($count === 0) {
			throw new QueueStatusException();
		}

		$queue->setStatus(RequestQueue::STATUS_SUCCESS);
	}

	/**
	 * @throws QueueStatusException|Exception
	 */
	public function setAsFailure(RequestQueue &$queue): void {
		$qb = $this->getRequestQueueUpdateSql();
		$func = $qb->func();
		$expr = $qb->expr();

		$qb->set('status', $qb->createNamedParameter(RequestQueue::STATUS_STANDBY))
			->set('tries', $func->add('tries', $expr->literal(1)));
		$this->limitToId($qb, $queue->getId());
		$this->limitToStatus($qb, RequestQueue::STATUS_RUNNING);

		$count = $qb->executeStatement();

		if ($count === 0) {
			throw new QueueStatusException();
		}

		$queue->setStatus(RequestQueue::STATUS_STANDBY);
	}

	/**
	 * Return every request stuck `running` since before $before to standby.
	 *
	 * @return int the number of requests re-queued
	 * @throws Exception
	 */
	public function resetStaleRunning(int $before): int {
		$qb = $this->getRequestQueueUpdateSql();
		$qb->set('status', $qb->createNamedParameter(RequestQueue::STATUS_STANDBY));
		$this->limitToStatus($qb, RequestQueue::STATUS_RUNNING);
		$qb->andWhere(
			$qb->expr()->lt('last', $qb->createNamedParameter(
				new DateTime('@' . $before), IQueryBuilder::PARAM_DATE
			))
		);

		return $qb->executeStatement();
	}

	public function delete(RequestQueue $queue): void {
		$qb = $this->getRequestQueueDeleteSql();
		$this->limitToId($qb, $queue->getId());

		$qb->executeStatement();
	}

	public function deleteByAuthor(string $actorId): void {
		$qb = $this->getRequestQueueDeleteSql();
		$qb->limitToDBField('author_prim', $qb->prim($actorId));

		$qb->executeStatement();
	}

	//	public function moveAccount(string $actorId, string $newId, string $instance): void {
	//		$qb = $this->getRequestQueueUpdateSql();
	//		$qb->set('author', $qb->createNamedParameter($newId))
	//		   ->set('author_prim', $qb->createNamedParameter($qb->prim($newId)))
	//		   ->set('instance', $qb->createNamedParameter($instance));
	//		$qb->limitToDBField('author_prim', $qb->prim($actorId));
	//
	//		$qb->executeStatement();
	//	}
}
