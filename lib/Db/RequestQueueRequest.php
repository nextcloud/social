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
			->setValue('object_id_prim', $qb->createNamedParameter($queue->getObjectIdPrim()))
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
		// what the drain has given up on is marked first: the query below cannot
		// return those rows any more, and the author is entitled to see that a
		// server never got their post rather than watch the row vanish
		$this->abandonExhausted($maxTries);

		$qb = $this->getRequestQueueSelectSql();
		$qb->limitToStatus(RequestQueue::STATUS_STANDBY);
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
	 * How many standby requests were last attempted before `$before` and are
	 * still waiting.
	 *
	 * The longest wait in the retry schedule is fourteen hours, so a row that
	 * has sat on standby for a day past its last attempt is one no drain has
	 * come back for: the cron is not running, or something ahead of it in the
	 * batch never lets it through. A row that was never attempted has no
	 * `last` and is not counted — its age is unknown.
	 *
	 * @throws Exception
	 */
	public function countStandbyOlderThan(int $before): int {
		$qb = $this->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from(self::TABLE_REQUEST_QUEUE)
			->where($qb->expr()->eq('status', $qb->createNamedParameter(RequestQueue::STATUS_STANDBY, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('last'))
			->andWhere($qb->expr()->lt('last', $qb->createNamedParameter(
				new DateTime('@' . $before), IQueryBuilder::PARAM_DATE
			)));

		$cursor = $qb->executeQuery();
		$total = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $total;
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
		// still in play: a row kept as delivered or abandoned has tries too,
		// and is not a delivery anybody is worried about
		$qb->limitToStatus(RequestQueue::STATUS_STANDBY);
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
	 * The deliveries this instance has given up on, the most recently
	 * abandoned first.
	 *
	 * They are the ones nobody saw: a failing request is at least counted
	 * while it is still being retried, and then it changes status and drops
	 * out of every summary there is. The rows live `RETENTION_SECONDS` (seven
	 * days) past their last attempt, which is exactly the window in which an
	 * administrator can still be told that a server stopped receiving
	 * anything from here.
	 *
	 * @return list<RequestQueue>
	 * @throws Exception
	 */
	public function getAbandoned(int $limit = 500): array {
		$qb = $this->getRequestQueueSelectSql();
		$qb->limitToStatus(RequestQueue::STATUS_ABANDONED);
		$qb->orderBy('last', 'desc');
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
			$qb->limitToStatus($status);
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
		$qb->limitToId($queue->getId());
		$qb->limitToStatus(RequestQueue::STATUS_STANDBY);

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
		$qb->limitToId($queue->getId());
		$qb->limitToStatus(RequestQueue::STATUS_RUNNING);

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
		$qb->limitToId($queue->getId());
		$qb->limitToStatus(RequestQueue::STATUS_RUNNING);

		$count = $qb->executeStatement();

		if ($count === 0) {
			throw new QueueStatusException();
		}

		$queue->setStatus(RequestQueue::STATUS_STANDBY);
	}

	/**
	 * Moves a standby request out of the due window until `$until`.
	 *
	 * `last` is what the retry schedule is measured from, so a timestamp in
	 * the future both holds the row back (`limitToQueueDue()`) and sorts it
	 * behind everything that is due (`getStandby()`), without spending one of
	 * its tries on an attempt that was never made.
	 *
	 * @throws QueueStatusException when the row was not on standby any more
	 * @throws Exception
	 */
	public function postpone(RequestQueue &$queue, int $until): void {
		$qb = $this->getRequestQueueUpdateSql();
		$qb->set('last', $qb->createNamedParameter(
			new DateTime('@' . $until), IQueryBuilder::PARAM_DATE
		));
		$qb->limitToId($queue->getId());
		$qb->limitToStatus(RequestQueue::STATUS_STANDBY);

		if ($qb->executeStatement() === 0) {
			throw new QueueStatusException();
		}

		$queue->setLast($until);
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
		$qb->limitToStatus(RequestQueue::STATUS_RUNNING);
		$qb->andWhere(
			$qb->expr()->lt('last', $qb->createNamedParameter(
				new DateTime('@' . $before), IQueryBuilder::PARAM_DATE
			))
		);

		return $qb->executeStatement();
	}

	public function delete(RequestQueue $queue): void {
		$qb = $this->getRequestQueueDeleteSql();
		$qb->limitToId($queue->getId());

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
	protected function limitToQueueDue(IExtendedQueryBuilder $qb, int $maxTries): void {
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
	/**
	 * Gives up on one standby row: kept as abandoned until `deleteFinished()`.
	 *
	 * @throws QueueStatusException when the row was not on standby any more
	 */
	public function setAsAbandoned(RequestQueue &$queue): void {
		$qb = $this->getRequestQueueUpdateSql();
		$qb->set('status', $qb->createNamedParameter(RequestQueue::STATUS_ABANDONED));
		$qb->limitToId($queue->getId());
		$qb->limitToStatus(RequestQueue::STATUS_STANDBY);

		if ($qb->executeStatement() === 0) {
			throw new QueueStatusException();
		}

		$queue->setStatus(RequestQueue::STATUS_ABANDONED);
	}

	/**
	 * Marks what the drain has given up on, rather than deleting it.
	 *
	 * The row stays so that the post it belongs to can say a server never got
	 * it; `deleteFinished()` takes it away with the delivered ones once the
	 * retention has passed.
	 *
	 * @return int rows marked
	 */
	public function abandonExhausted(int $maxTries = RequestQueueService::MAX_TRIES): int {
		$qb = $this->getRequestQueueUpdateSql();
		$qb->set('status', $qb->createNamedParameter(RequestQueue::STATUS_ABANDONED));
		$qb->limitToStatus(RequestQueue::STATUS_STANDBY);
		$qb->andWhere(
			$qb->expr()->gte('tries', $qb->createNamedParameter($maxTries, IQueryBuilder::PARAM_INT))
		);

		return $qb->executeStatement();
	}

	/**
	 * Removes delivered and abandoned rows whose last attempt is older than
	 * `$before`: what the author could have asked about has been kept long
	 * enough, and a queue is not an archive.
	 *
	 * @return int rows removed
	 */
	public function deleteFinished(int $before): int {
		$qb = $this->getRequestQueueDeleteSql();
		$expr = $qb->expr();
		$qb->andWhere($expr->in('status', $qb->createNamedParameter(
			[RequestQueue::STATUS_SUCCESS, RequestQueue::STATUS_ABANDONED], IQueryBuilder::PARAM_INT_ARRAY
		)));
		$qb->andWhere($expr->lt('last', $qb->createNamedParameter(
			new DateTime('@' . $before), IQueryBuilder::PARAM_DATE
		)));

		return $qb->executeStatement();
	}

	/**
	 * Every queued delivery of one object, as the author is entitled to see it.
	 *
	 * @return list<RequestQueue>
	 */
	public function getByObject(string $objectIdPrim): array {
		if ($objectIdPrim === '') {
			return [];
		}

		$qb = $this->getRequestQueueSelectSql();
		$qb->limitToDBField('object_id_prim', $objectIdPrim);
		$qb->orderBy('rq.id', 'asc');

		$requests = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$requests[] = $this->parseRequestQueueSelectSql($data);
		}
		$cursor->closeCursor();

		return $requests;
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
