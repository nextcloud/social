<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\RecommendationDiscoveryHealthRequest;
use OCA\Social\Service\Discovery\PostSourceStats;
use OCP\AppFramework\Utility\ITimeFactory;

/** Reads and records instance-wide provider health without retaining query terms or readers. */
class RecommendationDiscoveryHealthService {
	public const DEFAULT_WINDOW_HOURS = 24;
	public const MAX_WINDOW_HOURS = 168;

	public function __construct(
		private RecommendationDiscoveryHealthRequest $request,
		private ITimeFactory $timeFactory,
	) {
	}

	public function record(string $provider, PostSourceStats $stats): void {
		if ($stats->requests > 0) {
			$this->request->record($provider, $stats, $this->timeFactory->getTime());
		}
	}

	/** @return array{window_hours: int, sources: array<string, array{state: string, calls: int, requests: int, failed_requests: int, rate_limited: int, posts_seen: int, posts_stored: int, average_duration_ms: ?int, failure_rate: ?float, last_attempt: int, last_success: int}>} */
	public function summary(int $windowHours = self::DEFAULT_WINDOW_HOURS): array {
		$windowHours = max(1, min(self::MAX_WINDOW_HOURS, $windowHours));
		$since = $this->timeFactory->getTime() - $windowHours * 3600;
		$totals = $this->request->summary($since);
		$sources = [];
		foreach ($totals as $provider => $source) {
			$sources[$provider] = [
				'state' => self::state($source),
				'calls' => $source['calls'],
				'requests' => $source['requests'],
				'failed_requests' => $source['failed_requests'],
				'rate_limited' => $source['rate_limited'],
				'posts_seen' => $source['posts_seen'],
				'posts_stored' => $source['posts_stored'],
				'average_duration_ms' => $source['average_duration_ms'],
				'failure_rate' => $source['requests'] > 0
				? round($source['failed_requests'] / $source['requests'], 4)
				: null,
				'last_attempt' => $source['last_attempt'],
				'last_success' => $source['last_success'],
			];
		}

		return ['window_hours' => $windowHours, 'sources' => $sources];
	}

	private static function state(array $source): string {
		if ($source['calls'] === 0) {
			return 'no_data';
		}
		if ($source['failed_requests'] >= $source['requests']) {
			return 'unavailable';
		}
		return $source['failed_requests'] > 0 || $source['rate_limited'] > 0 ? 'degraded' : 'healthy';
	}
}
