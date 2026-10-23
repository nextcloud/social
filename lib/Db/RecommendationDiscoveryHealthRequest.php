<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use InvalidArgumentException;
use OCA\Social\Service\Discovery\PostSourceStats;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Bounded, instance-wide hourly aggregates for For You source operations. */
class RecommendationDiscoveryHealthRequest extends CoreRequestBuilder {
	public const TABLE = CoreRequestBuilder::TABLE_RECOMMENDATION_DISCOVERY;
	private const RETENTION_SECONDS = 7 * 86400;

	public function record(string $provider, PostSourceStats $stats, int $now): void {
		if (!in_array($provider, ['activitypub', 'atproto'], true) || $stats->requests < 1) {
			throw new InvalidArgumentException('Invalid recommendation discovery sample');
		}
		$bucket = intdiv($now, 3600) * 3600;
		$lastSuccess = $stats->failedRequests < $stats->requests ? $now : 0;
		$inserted = $this->dbConnection->insertIgnoreConflict(self::TABLE, [
			'provider' => $provider,
			'hour_bucket' => $bucket,
			'calls' => 1,
			'requests' => $stats->requests,
			'failed_requests' => $stats->failedRequests,
			'rate_limited' => $stats->rateLimitedRequests,
			'posts_seen' => $stats->postsSeen,
			'posts_stored' => $stats->postsStored,
			'duration_ms' => $stats->durationMs,
			'last_attempt' => $now,
			'last_success' => $lastSuccess,
		]);
		if ($inserted === 0) {
			$qb = $this->getQueryBuilder();
			$qb->update(self::TABLE)
				->set('calls', $qb->func()->add('calls', $qb->expr()->literal(1)))
				->set('requests', $qb->func()->add('requests', $qb->createNamedParameter($stats->requests, IQueryBuilder::PARAM_INT)))
				->set('failed_requests', $qb->func()->add('failed_requests', $qb->createNamedParameter($stats->failedRequests, IQueryBuilder::PARAM_INT)))
				->set('rate_limited', $qb->func()->add('rate_limited', $qb->createNamedParameter($stats->rateLimitedRequests, IQueryBuilder::PARAM_INT)))
				->set('posts_seen', $qb->func()->add('posts_seen', $qb->createNamedParameter($stats->postsSeen, IQueryBuilder::PARAM_INT)))
				->set('posts_stored', $qb->func()->add('posts_stored', $qb->createNamedParameter($stats->postsStored, IQueryBuilder::PARAM_INT)))
				->set('duration_ms', $qb->func()->add('duration_ms', $qb->createNamedParameter($stats->durationMs, IQueryBuilder::PARAM_INT)))
				->set('last_attempt', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('provider', $qb->createNamedParameter($provider)))
				->andWhere($qb->expr()->eq('hour_bucket', $qb->createNamedParameter($bucket, IQueryBuilder::PARAM_INT)));
			if ($lastSuccess > 0) {
				$qb->set('last_success', $qb->createNamedParameter($lastSuccess, IQueryBuilder::PARAM_INT));
			}
			$qb->executeStatement();
		}

		$purge = $this->getQueryBuilder();
		$purge->delete(self::TABLE)
			->where($purge->expr()->lt('hour_bucket', $purge->createNamedParameter($bucket - self::RETENTION_SECONDS, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @return array<string, array{calls: int, requests: int, failed_requests: int, rate_limited: int, posts_seen: int, posts_stored: int, average_duration_ms: ?int, last_attempt: int, last_success: int}> */
	public function summary(int $since): array {
		/** @var array<string, array{calls: int, requests: int, failed_requests: int, rate_limited: int, posts_seen: int, posts_stored: int, duration_ms: int, last_attempt: int, last_success: int}> $totals */
		$totals = [];
		$summary = [];
		foreach (['activitypub', 'atproto'] as $provider) {
			$totals[$provider] = [
				'calls' => 0, 'requests' => 0, 'failed_requests' => 0, 'rate_limited' => 0,
				'posts_seen' => 0, 'posts_stored' => 0, 'duration_ms' => 0, 'last_attempt' => 0, 'last_success' => 0,
			];
		}
		$qb = $this->getQueryBuilder();
		$qb->select('provider', 'calls', 'requests', 'failed_requests', 'rate_limited', 'posts_seen', 'posts_stored', 'duration_ms', 'last_attempt', 'last_success')
			->from(self::TABLE)
			->where($qb->expr()->gte('hour_bucket', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$provider = (string)$row['provider'];
			if (!isset($totals[$provider])) {
				continue;
			}
			foreach (['calls', 'requests', 'failed_requests', 'rate_limited', 'posts_seen', 'posts_stored', 'duration_ms'] as $counter) {
				$totals[$provider][$counter] += (int)$row[$counter];
			}
			$totals[$provider]['last_attempt'] = max($totals[$provider]['last_attempt'], (int)$row['last_attempt']);
			$totals[$provider]['last_success'] = max($totals[$provider]['last_success'], (int)$row['last_success']);
		}
		$result->closeCursor();

		foreach ($totals as $provider => $row) {
			$summary[$provider] = [
				'calls' => $row['calls'],
				'requests' => $row['requests'],
				'failed_requests' => $row['failed_requests'],
				'rate_limited' => $row['rate_limited'],
				'posts_seen' => $row['posts_seen'],
				'posts_stored' => $row['posts_stored'],
				'average_duration_ms' => $row['calls'] > 0 ? (int)round($row['duration_ms'] / $row['calls']) : null,
				'last_attempt' => $row['last_attempt'],
				'last_success' => $row['last_success'],
			];
		}

		return $summary;
	}
}
