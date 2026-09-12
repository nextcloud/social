<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Exceptions\EmptyQueueException;
use OCA\Social\Exceptions\NoHighPriorityRequestException;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Tools\Traits\TArrayTools;

class RequestQueueService {
	/**
	 * A request is abandoned after this many failed delivery attempts.
	 *
	 * The schedule is Mastodon's (Sidekiq's `count^4 + 15` backoff, 16
	 * attempts): a peer gets about two days to come back, which covers the
	 * weekend outage the old 15 tries on `tries^4/3` (12-16 hours in total)
	 * did not. The first attempts stay minutes apart, because a peer that was
	 * only momentarily down is the common case; the cron runs every 12
	 * minutes, so every wait below that is "next run". A row is retried once
	 * `last + retryDelay(tries)` has passed:
	 *
	 *   failed tries | wait before the next attempt
	 *   -------------|-----------------------------
	 *              0 |  none: never failed, due at once
	 *              1 |      16 s
	 *              2 |      31 s
	 *              3 |      96 s
	 *              4 |     271 s  (4.5 min)
	 *              5 |     640 s  (11 min)
	 *              6 |   1 311 s  (22 min)
	 *              7 |   2 416 s  (40 min)
	 *              8 |   4 111 s  (1.1 h)
	 *              9 |   6 576 s  (1.8 h)
	 *             10 |  10 015 s  (2.8 h)
	 *             11 |  14 656 s  (4.1 h)
	 *             12 |  20 751 s  (5.8 h)
	 *             13 |  28 576 s  (7.9 h)
	 *             14 |  38 431 s  (10.7 h)
	 *             15 |  50 640 s  (14.1 h)
	 *             16 |  abandoned
	 *
	 * Total: 178 537 s, 49.6 hours, plus up to one cron interval per attempt.
	 *
	 * The same schedule has to be applied by the query that reads the queue
	 * (`RequestQueueRequest::limitToQueueDue()`), so both sides call
	 * `retryDelay()`.
	 */
	public const MAX_TRIES = 16;

	/**
	 * How long a request waits after its n-th failure, in seconds; the table
	 * on MAX_TRIES. `tries` is the count of failed attempts so far.
	 */
	public static function retryDelay(int $tries): int {
		if ($tries < 1) {
			return 0;
		}

		return $tries ** 4 + 15;
	}

	/** A `running` request older than this (seconds) is treated as stranded. */
	public const STALE_RUNNING_SECONDS = 3600;

	use TArrayTools;

	public function __construct(
		private RequestQueueRequest $requestQueueRequest,
		private ConfigService $configService,
		private MiscService $miscService,
	) {
	}

	/**
	 * @param array $instancePaths
	 * @param ACore $item
	 * @param string $author
	 *
	 * @return string
	 */
	public function generateRequestQueue(array $instancePaths, ACore $item, string $author): string {
		return $this->generateRequestQueueFromSource(
			$instancePaths, json_encode($item, JSON_UNESCAPED_SLASHES), $author
		);
	}

	/**
	 * Queues a document that is already serialised, byte for byte.
	 *
	 * Forwarding has to send on what arrived: the sender's linked-data
	 * signature covers the document as it stands, and re-encoding our own
	 * model of it would both drop the signature and change what it signed.
	 *
	 * @param InstancePath[] $instancePaths
	 *
	 * @return string the token shared by every queued request
	 */
	public function generateRequestQueueFromSource(array $instancePaths, string $activity, string $author): string {
		$token = '';
		$requests = [];
		foreach ($this->uniqueInboxes($instancePaths) as $instancePath) {
			$request = new RequestQueue($activity, $instancePath, $author);
			if ($token === '') {
				$token = $request->getToken();
			} else {
				$request->setToken($token);
			}

			$requests[] = $request;
		}

		$this->requestQueueRequest->multiple($requests);

		return $token;
	}

	/**
	 * One delivery per inbox.
	 *
	 * The paths reaching here come from several places that know nothing of
	 * each other: every mention adds the mentioned actor's inbox, the follower
	 * fan-out adds one path per instance, boosters and repliers add theirs. A
	 * post mentioning three people on one Mastodon server, or mentioning
	 * somebody who also follows the author, produced that many identical
	 * POSTs of the same activity to the same inbox — wasted on us, and
	 * duplicate work for the peer, which drops all but the first as already
	 * seen.
	 *
	 * Same inbox means same URI: the callers already prefer an instance's
	 * shared inbox where it publishes one, so the shared inbox is the single
	 * URI they converge on. An instance that publishes none is addressed by
	 * its personal inboxes, which are distinct URIs and each still delivered —
	 * uniquing must not turn two people on such a server into one delivery.
	 *
	 * Of two paths to one inbox the higher priority wins, so folding a
	 * follower fan-out into a direct mention does not demote the delivery from
	 * inline to the next cron run.
	 *
	 * @param InstancePath[] $instancePaths
	 *
	 * @return InstancePath[] in the order the inboxes were first named
	 */
	private function uniqueInboxes(array $instancePaths): array {
		$unique = [];
		foreach ($instancePaths as $instancePath) {
			$uri = $instancePath->getUri();
			if (!array_key_exists($uri, $unique)) {
				$unique[$uri] = $instancePath;
				continue;
			}

			if ($instancePath->getPriority() > $unique[$uri]->getPriority()) {
				$unique[$uri] = $instancePath;
			}
		}

		return array_values($unique);
	}

	/**
	 * deciding if we run request on main thread,
	 * based on set priority, and number of request linked to one token
	 *
	 * @param string $token
	 *
	 * @return RequestQueue
	 * @throws EmptyQueueException
	 * @throws NoHighPriorityRequestException
	 */
	public function getPriorityRequest(string $token): RequestQueue {
		$requests = $this->requestQueueRequest->getFromToken($token);

		if (sizeof($requests) === 0) {
			throw new EmptyQueueException();
		}

		$request = $requests[0];
		switch ($request->getPriority()) {
			case InstancePath::PRIORITY_TOP:
				return $request;
			case InstancePath::PRIORITY_HIGH:
				if (sizeof($requests) === 1) {
					return $request;
				}

				$next = $requests[1];
				if ($next->getPriority() < InstancePath::PRIORITY_HIGH) {
					return $request;
				}
				break;

			case InstancePath::PRIORITY_MEDIUM:
				if (sizeof($requests) === 1) {
					return $request;
				}
				break;
		}

		throw new NoHighPriorityRequestException();
	}

	/**
	 * @param int $total
	 *
	 * @return RequestQueue[]
	 */
	public function getRequestStandby(int &$total = 0): array {
		$requests = $this->requestQueueRequest->getStandby();
		$total = sizeof($requests);

		$result = [];
		foreach ($requests as $request) {
			// A request that has exhausted its retries is abandoned rather than kept
			// on standby forever against a host that is never coming back.
			if ($request->getTries() >= self::MAX_TRIES) {
				$this->deleteRequest($request);
				continue;
			}

			$delay = self::retryDelay($request->getTries());
			if ($request->getLast() < (time() - $delay)) {
				$result[] = $request;
			}
		}

		return $result;
	}

	/**
	 * @param string $token
	 * @param int $status
	 *
	 * @return RequestQueue[]
	 */
	public function getRequestFromToken(string $token, int $status = -1): array {
		if ($token === '') {
			return [];
		}

		return $this->requestQueueRequest->getFromToken($token, $status);
	}

	/**
	 * @param RequestQueue $queue
	 *
	 * @throws QueueStatusException
	 */
	public function initRequest(RequestQueue $queue) {
		$this->requestQueueRequest->setAsRunning($queue);
	}

	/**
	 * @param RequestQueue $queue
	 * @param bool $success
	 */
	public function endRequest(RequestQueue $queue, bool $success) {
		try {
			if ($success === true) {
				// A successfully delivered request has nothing left to record, so it is
				// removed rather than kept forever as a STATUS_SUCCESS row.
				$this->requestQueueRequest->delete($queue);
			} else {
				$this->requestQueueRequest->setAsFailure($queue);
			}
		} catch (QueueStatusException $e) {
		}
	}

	/**
	 * Return requests stuck `running` past the reaper cutoff to standby, so a worker
	 * that died mid-delivery does not strand them forever.
	 */
	public function reapStaleRunning(): int {
		return $this->requestQueueRequest->resetStaleRunning(time() - self::STALE_RUNNING_SECONDS);
	}

	/**
	 * @param RequestQueue $queue
	 */
	public function deleteRequest(RequestQueue $queue) {
		$this->requestQueueRequest->delete($queue);
	}
}
