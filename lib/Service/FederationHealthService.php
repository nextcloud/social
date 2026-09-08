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
 * Delivery failures are invisible today: a request that cannot be delivered is
 * retried on a widening delay and then, after MAX_TRIES attempts, dropped
 * without a word. From the outside an instance that has quietly stopped
 * receiving anything from us looks exactly like an instance nobody has posted
 * to. This turns the queue into an answer to "is anything not getting through,
 * and to whom".
 */
class FederationHealthService {
	/** Only the worst offenders are worth a table row. */
	public const TOP_INSTANCES = 10;

	/** Past this many failures a request is on its last legs. */
	public const AT_RISK_TRIES = 8;

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
	 *     maxTries: int,
	 *     truncated: bool,
	 *     instances: list<array{host: string, requests: int, tries: int, last: int}>
	 * }
	 */
	public function summary(): array {
		$counts = $this->requestQueueRequest->countByStatus();
		$failing = $this->requestQueueRequest->getFailing();

		return [
			'waiting' => $counts[RequestQueue::STATUS_STANDBY] ?? 0,
			'running' => $counts[RequestQueue::STATUS_RUNNING] ?? 0,
			'failing' => count($failing),
			'atRisk' => count(array_filter(
				$failing,
				fn (RequestQueue $request): bool => $request->getTries() >= self::AT_RISK_TRIES
			)),
			'maxTries' => RequestQueueService::MAX_TRIES,
			// the read is capped, so a very sick instance reports "at least"
			'truncated' => count($failing) >= 500,
			'instances' => $this->byInstance($failing),
		];
	}

	/**
	 * The failing requests gathered per host, worst first. Grouping happens
	 * here rather than in SQL because the address is inside a JSON column, and
	 * a query that can pick it apart is not the same query on every database
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

		return array_slice(array_values($hosts), 0, self::TOP_INSTANCES);
	}
}
