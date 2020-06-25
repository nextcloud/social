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
use OCA\Social\Model\StreamQueue;
use OCP\DB\QueryBuilder\IQueryBuilder;


/**
 * Class StreamQueueRequest
 *
 * @package OCA\Social\Db
 */
class StreamQueueRequest extends StreamQueueRequestBuilder {


	/**
	 * create a new Queue in the database.
	 *
	 * @param StreamQueue $queue
	 */
	public function create(StreamQueue $queue) {
		$qb = $this->getStreamQueueInsertSql();
		$qb->setValue('token', $qb->createNamedParameter($queue->getToken()))
		   ->setValue('stream_id', $qb->createNamedParameter($queue->getStreamId()))
		   ->setValue('type', $qb->createNamedParameter($queue->getType()))
		   ->setValue('status', $qb->createNamedParameter($queue->getStatus()))
		   ->setValue('tries', $qb->createNamedParameter($queue->getTries()));
		$qb->execute();
	}


	/**
	 * return Queue from database based on the status=0
	 *
	 * @return StreamQueue[]
	 */
	public function getStandby(): array {
		$qb = $this->getStreamQueueSelectSql();
		$this->limitToStatus($qb, StreamQueue::STATUS_STANDBY);
		$qb->orderBy('id', 'asc');

		$requests = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$requests[] = $this->parseStreamQueueSelectSql($data);
		}
		$cursor->closeCursor();

		return $requests;
	}


	/**
	 * return Queue from database based on the token
	 *
	 * @param string $token
	 *
	 * @return StreamQueue[]
	 */
	public function getFromToken(string $token): array {
		$qb = $this->getStreamQueueSelectSql();
		$qb->limitToToken($token);

		$queue = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$queue[] = $this->parseStreamQueueSelectSql($data);
		}
		$cursor->closeCursor();

		return $queue;
	}


	/**
	 * @param StreamQueue $queue
	 *
	 * @throws QueueStatusException
	 */
	public function setAsRunning(StreamQueue &$queue) {
		$qb = $this->getStreamQueueUpdateSql();
		$qb->set('status', $qb->createNamedParameter(StreamQueue::STATUS_RUNNING))
		   ->set(
			   'last',
			   $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
		   );
		$this->limitToId($qb, $queue->getId());
		$this->limitToStatus($qb, StreamQueue::STATUS_STANDBY);

		$count = $qb->execute();

		if ($count === 0) {
			throw new QueueStatusException();
		}

		$queue->setStatus(StreamQueue::STATUS_RUNNING);
	}


	/**
	 * @param StreamQueue $queue
	 *
	 * @throws QueueStatusException
	 */
	public function setAsSuccess(StreamQueue &$queue) {
		$qb = $this->getStreamQueueUpdateSql();
		$qb->set('status', $qb->createNamedParameter(StreamQueue::STATUS_SUCCESS));
		$this->limitToId($qb, $queue->getId());
		$this->limitToStatus($qb, StreamQueue::STATUS_RUNNING);

		$count = $qb->execute();

		if ($count === 0) {
			throw new QueueStatusException();
		}

		$queue->setStatus(StreamQueue::STATUS_SUCCESS);
	}


	/**
	 * @param StreamQueue $queue
	 *
	 * @throws QueueStatusException
	 */
	public function setAsFailure(StreamQueue &$queue) {
		$qb = $this->getStreamQueueUpdateSql();
		$func = $qb->func();
		$expr = $qb->expr();

		$qb->set('status', $qb->createNamedParameter(StreamQueue::STATUS_STANDBY))
		   ->set('tries', $func->add('tries', $expr->literal(1)));
		$this->limitToId($qb, $queue->getId());
		$this->limitToStatus($qb, StreamQueue::STATUS_RUNNING);

		$count = $qb->execute();

		if ($count === 0) {
			throw new QueueStatusException();
		}

		$queue->setStatus(StreamQueue::STATUS_SUCCESS);
	}


	/**
	 * @param StreamQueue $queue
	 */
	public function delete(StreamQueue $queue) {
		$qb = $this->getStreamQueueDeleteSql();
		$this->limitToId($qb, $queue->getId());

		$qb->execute();
	}

}

