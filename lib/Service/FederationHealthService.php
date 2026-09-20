<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Model\RequestQueue;

/**
 * What the outbound queue looks like right now, in terms an administrator can
 * act on.
 *
 * Delivery failures used to be invisible: a request that cannot be delivered is
 * retried on a widening delay and then, after MAX_TRIES attempts, given up on.
 * From the outside an instance that has quietly stopped receiving anything from
 * us looks exactly like an instance nobody has posted to. This turns the queue
 * into an answer to "is anything not getting through, and to whom".
 *
 * Both halves of that: what is still being retried, and what has been given up
 * on. The second half is the one that matters most and was reported nowhere —
 * a request on its fifteenth attempt was counted as failing, and the moment it
 * was abandoned it left every count, so the queue looked healthiest exactly
 * when a peer had been lost for good.
 */
class FederationHealthService {
	/** Only the worst offenders are worth a table row. */
	public const TOP_INSTANCES = 10;

	/** Past this many failures a request is on its last legs. */
	public const AT_RISK_TRIES = 8;

	/**
	 * A standby row last tried this long ago (seconds) is stuck, not waiting.
	 *
	 * The retry schedule never waits more than fourteen hours, so a day is
	 * past anything the queue would do on purpose.
	 */
	public const STALE_STANDBY_SECONDS = 24 * 3600;

	public function __construct(
		private RequestQueueRequest $requestQueueRequest,
	) {
	}

	/**
	 * @return array{
	 *     waiting: int,
	 *     running: int,
	 *     failing: int,
	 *     atRisk: int,
	 *     abandoned: int,
	 *     maxTries: int,
	 *     truncated: bool,
	 *     abandonedTruncated: bool,
	 *     retentionDays: int,
	 *     instances: list<array{host: string, requests: int, tries: int, last: int}>,
	 *     givenUp: list<array{host: string, requests: int, tries: int, last: int}>
	 * }
	 */
	public function summary(): array {
		$counts = $this->requestQueueRequest->countByStatus();
		$failing = $this->requestQueueRequest->getFailing();
		// the deliveries that are already over: they used to appear in nothing
		// at all, which made the one state an administrator has to act on the
		// one state nothing reported
		$abandoned = $this->requestQueueRequest->getAbandoned();

		return [
			'waiting' => $counts[RequestQueue::STATUS_STANDBY] ?? 0,
			'running' => $counts[RequestQueue::STATUS_RUNNING] ?? 0,
			'failing' => count($failing),
			'atRisk' => count(array_filter(
				$failing,
				fn (RequestQueue $request): bool => $request->getTries() >= self::AT_RISK_TRIES
			)),
			'abandoned' => count($abandoned),
			'maxTries' => RequestQueueService::MAX_TRIES,
			// the read is capped, so a very sick instance reports "at least"
			'truncated' => count($failing) >= 500,
			'abandonedTruncated' => count($abandoned) >= 500,
			// how far back the abandoned count reaches: the rows are purged
			// after this, so it is a window and not a total
			'retentionDays' => (int)round(RequestQueueService::RETENTION_SECONDS / 86400),
			// when the longest-failing delivery was last tried: the difference
			// between a peer rebooting and a delivery that is never going to
			// happen, which the counts alone could not tell an administrator
			'stuckSince' => $this->requestQueueRequest->oldestFailingAttempt(),
			'instances' => $this->byInstance($failing),
			'givenUp' => $this->byInstance($abandoned),
		];
	}

	/**
	 * What in the queue is not going to move on its own: the rows the drain
	 * has given up on, and the ones it should have come back for a day ago
	 * and has not. Both are zero on a healthy instance, which is what the
	 * setup check asks.
	 *
	 * @return array{abandoned: int, stale: int}
	 */
	public function stuck(): array {
		$counts = $this->requestQueueRequest->countByStatus();

		return [
			'abandoned' => $counts[RequestQueue::STATUS_ABANDONED] ?? 0,
			'stale' => $this->requestQueueRequest->countStandbyOlderThan(time() - self::STALE_STANDBY_SECONDS),
		];
	}

	/**
	 * Requests gathered per host, worst first — the same shape for the ones
	 * still being retried and the ones given up on. Grouping happens here
	 * rather than in SQL because the address is inside a JSON column, and a
	 * query that can pick it apart is not the same query on every database
	 * this app supports.
	 *
	 * @param RequestQueue[] $failing
	 *
	 * @return list<array{host: string, requests: int, tries: int, last: int}>
	 */
	private function byInstance(array $failing): array {
		$hosts = [];
		foreach ($failing as $request) {
			$instance = $request->getInstance();
			$host = $instance === null ? '' : $instance->getAddress();
			if ($host === '') {
				continue;
			}

			if (!isset($hosts[$host])) {
				$hosts[$host] = ['host' => $host, 'requests' => 0, 'tries' => 0, 'last' => 0];
			}

			$hosts[$host]['requests']++;
			$hosts[$host]['tries'] = max($hosts[$host]['tries'], $request->getTries());
			$hosts[$host]['last'] = max($hosts[$host]['last'], $request->getLast());
		}

		usort($hosts, function (array $one, array $other): int {
			return [$other['tries'], $other['requests']] <=> [$one['tries'], $one['requests']];
		});

		return array_slice($hosts, 0, self::TOP_INSTANCES);
	}
}
