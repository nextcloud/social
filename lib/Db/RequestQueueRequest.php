<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Social\Db;


use DateTime;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Model\RequestQueue;
use OCP\DB\QueryBuilder\IQueryBuilder;


/**
 * Class RequestQueueRequest
 *
 * @package OCA\Social\Db
 */
class RequestQueueRequest extends RequestQueueRequestBuilder {


	/**
	 * create a new Queue in the database.
	 *
	 * @param RequestQueue[] $queues
	 */
	public function multiple(array $queues) {
		foreach ($queues as $queue) {
			$this->create($queue);
		}
	}


	/**
	 * create a new Queue in the database.
	 *
	 * @param RequestQueue $queue
	 */
	public function create(RequestQueue $queue) {
		$qb = $this->getRequestQueueInsertSql();
		$qb->setValue('token', $qb->createNamedParameter($queue->getToken()))
		   ->setValue('author', $qb->createNamedParameter($queue->getAuthor()))
		   ->setValue('activity', $qb->createNamedParameter($queue->getActivity()))
		   ->setValue(
			   'instance', $qb->createNamedParameter(
			   json_encode($queue->getInstance(), JSON_UNESCAPED_SLASHES)
		   )
		   )
		   ->setValue('priority', $qb->createNamedParameter($queue->getPriority()))
		   ->setValue('status', $qb->createNamedParameter($queue->getStatus()))
		   ->setValue('tries', $qb->createNamedParameter($queue->getTries()));
		$qb->execute();
	}


	/**
	 * return Queue from database based on the status=0
	 *
	 * @return RequestQueue[]
	 */
	public function getStandby(): array {
		$qb = $this->getRequestQueueSelectSql();
		$this->limitToStatus($qb, RequestQueue::STATUS_STANDBY);
		$qb->orderBy('id', 'asc');

		$requests = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$requests[] = $this->parseRequestQueueSelectSql($data);
		}
		$cursor->closeCursor();

		return $requests;
	}


	/**
	 * return Queue from database based on the token
	 *
	 * @param string $token
	 * @param int $status
	 *
	 * @return RequestQueue[]
	 */
	public function getFromToken(string $token, int $status = -1): array {
		$qb = $this->getRequestQueueSelectSql();
		$this->limitToToken($qb, $token);

		if ($status > -1) {
			$this->limitToStatus($qb, $status);
		}

		$qb->orderBy('priority', 'desc');

		$requests = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$requests[] = $this->parseRequestQueueSelectSql($data);
		}
		$cursor->closeCursor();

		return $requests;
	}


	/**
	 * @param RequestQueue $queue
	 *
	 * @throws QueueStatusException
	 */
	public function setAsRunning(RequestQueue &$queue) {
		$qb = $this->getRequestQueueUpdateSql();
		$qb->set('status', $qb->createNamedParameter(RequestQueue::STATUS_RUNNING))
		   ->set(
			   'last',
			   $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
		   );
		$this->limitToId($qb, $queue->getId());
		$this->limitToStatus($qb, RequestQueue::STATUS_STANDBY);

		$count = $qb->execute();

		if ($count === 0) {
			throw new QueueStatusException();
		}

		$queue->setStatus(RequestQueue::STATUS_RUNNING);
	}


	/**
	 * @param RequestQueue $queue
	 *
	 * @throws QueueStatusException
	 */
	public function setAsSuccess(RequestQueue &$queue) {
		$qb = $this->getRequestQueueUpdateSql();
		$qb->set('status', $qb->createNamedParameter(RequestQueue::STATUS_SUCCESS));
		$this->limitToId($qb, $queue->getId());
		$this->limitToStatus($qb, RequestQueue::STATUS_RUNNING);

		$count = $qb->execute();

		if ($count === 0) {
			throw new QueueStatusException();
		}

		$queue->setStatus(RequestQueue::STATUS_SUCCESS);
	}


	/**
	 * @param RequestQueue $queue
	 *
	 * @throws QueueStatusException
	 */
	public function setAsFailure(RequestQueue &$queue) {
		$qb = $this->getRequestQueueUpdateSql();
		$func = $qb->func();
		$expr = $qb->expr();

		$qb->set('status', $qb->createNamedParameter(RequestQueue::STATUS_STANDBY))
		   ->set('tries', $func->add('tries', $expr->literal(1)));
		$this->limitToId($qb, $queue->getId());
		$this->limitToStatus($qb, RequestQueue::STATUS_RUNNING);

		$count = $qb->execute();

		if ($count === 0) {
			throw new QueueStatusException();
		}

		$queue->setStatus(RequestQueue::STATUS_SUCCESS);
	}


	public function delete(RequestQueue $queue) {
		$qb = $this->getRequestQueueDeleteSql();
		$this->limitToId($qb, $queue->getId());

		$qb->execute();
	}

}

