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
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Tools\IExtendedQueryBuilder;
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
	public function getStandby(int $maxTries = RequestQueueService::MAX_TRIES): array {
		// what the drain has given up on goes first: the query below cannot
		// return those rows any more, and nothing else walks this table
		$this->deleteExhausted($maxTries);

		$qb = $this->getRequestQueueSelectSql();
		$this->limitToStatus($qb, RequestQueue::STATUS_STANDBY);
		// the retry backoff and the give-up threshold, in the query rather than
		// in PHP afterwards: one dead instance otherwise fills the whole window
		// with rows that are not due, and starves every other delivery
		$this->limitToQueueDue($qb, $maxTries);
		// what is most urgent first, then what has been tried least, then the
		// oldest attempt. 'id asc' alone handed the window to whatever was
		// queued earliest regardless of priority; `tries` comes before `last`
		// because a row that was never attempted has a NULL `last`, and where
		// NULL sorts differs between MySQL and PostgreSQL.
		$qb->orderBy('rq.priority', 'desc');
		$qb->addOrderBy('rq.tries', 'asc');
		$qb->addOrderBy('rq.last', 'asc');
		$qb->addOrderBy('rq.id', 'asc');
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

	/**
	 * The outbound retry schedule, in SQL.
	 *
	 * The parent's version unrolls the `tries^4/3` backoff the inbound stream
	 * queue still runs on. Deliveries wait on `RequestQueueService::retryDelay()`
	 * instead — Mastodon's schedule, about two days in total — and the query
	 * has to agree with the PHP side or a row is handed back and then dropped
	 * again on every pass. Same shape as the parent: one branch per try count
	 * below the give-up threshold, `tries = n AND (last IS NULL OR last <= now -
	 * delay(n))`; the threshold falls out of the same expression. A row that
	 * has never been attempted has a NULL `last`.
	 */
	#[\Override]
	protected function limitToQueueDue(IQueryBuilder &$qb, int $maxTries): void {
		$expr = $qb->expr();
		$pf = ($qb->getType() === IExtendedQueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$now = time();

		$due = $expr->orX();
		for ($tries = 0; $tries < $maxTries; $tries++) {
			$cutoff = new DateTime('@' . ($now - RequestQueueService::retryDelay($tries)));

			$due->add(
				$expr->andX(
					$expr->eq($pf . 'tries', $qb->createNamedParameter($tries, IQueryBuilder::PARAM_INT)),
					$expr->orX(
						$expr->isNull($pf . 'last'),
						$expr->lte($pf . 'last', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE))
					)
				)
			);
		}

		$qb->andWhere($due);
	}

	/**
	 * Drops the requests that have exhausted their retries. getStandby() no
	 * longer returns them — the threshold is part of the query — so this is
	 * what keeps the table from holding them forever.
	 *
	 * @return int rows removed
	 * @throws Exception
	 */
	public function deleteExhausted(int $maxTries = RequestQueueService::MAX_TRIES): int {
		$qb = $this->getRequestQueueDeleteSql();
		$qb->andWhere(
			$qb->expr()->gte('tries', $qb->createNamedParameter($maxTries, IQueryBuilder::PARAM_INT))
		);

		return $qb->executeStatement();
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
