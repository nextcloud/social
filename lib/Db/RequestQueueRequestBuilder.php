<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\RequestQueue;
use OCA\Social\Tools\Traits\TArrayTools;

class RequestQueueRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;


	/**
	 * Base of the Sql Insert request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getRequestQueueInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_REQUEST_QUEUE);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getRequestQueueUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_REQUEST_QUEUE);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getRequestQueueSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select(
			'rq.id', 'rq.token', 'rq.author', 'rq.activity', 'rq.instance', 'rq.priority',
			'rq.status', 'rq.tries', 'rq.last'
		)
		   ->from(self::TABLE_REQUEST_QUEUE, 'rq');

		$this->defaultSelectAlias = 'rq';
		$qb->setDefaultSelectAlias('rq');

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getRequestQueueDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_REQUEST_QUEUE);

		return $qb;
	}


	/**
	 * @param array $data
	 *
	 * @return RequestQueue
	 */
	public function parseRequestQueueSelectSql($data): RequestQueue {
		$queue = new RequestQueue();
		$queue->importFromDatabase($data);

		return $queue;
	}
}
