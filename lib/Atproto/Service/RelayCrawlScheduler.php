<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Service;

use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Firehose\FirehoseDaemon;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Requests relay backfill after ATProto is enabled and after the local
 * firehose has been unavailable beyond the period in which events are kept.
 * This runs from maintenance so relay timeouts never hold up a web request.
 */
class RelayCrawlScheduler {
	private const FAILED_RETRY = 15 * 60;
	private const OUTAGE_RETRY = 6 * 3600;
	private const INDEXING_GRACE = 3600;
	/** A missing AppView/relay index is retried sooner than a missed firehose replay window. */
	private const INDEXING_RETRY = 3600;

	public function __construct(
		private AtprotoConfig $config,
		private RelayClient $relays,
		private FirehoseDaemon $firehose,
		private AtprotoStatusService $status,
		private ConfigService $configService,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function run(): void {
		if (!$this->config->isEnabled()) {
			return;
		}

		$now = $this->time->getTime();
		$attempted = (int)$this->configService->getAppValue(ConfigService::ATPROTO_CRAWL_ATTEMPTED);
		$accepted = (int)$this->configService->getAppValue(ConfigService::ATPROTO_CRAWL_ACCEPTED);
		$status = $this->firehose->status();
		$missedReplayWindow = $status !== null
			&& !$status['running']
			&& $status['seen'] > 0
			&& $now - $status['seen'] > EventService::REPLAY_WINDOW;
		$firstRequest = $attempted === 0;
		$retryFailedInitialRequest = $accepted === 0 && $attempted > 0 && $now - $attempted >= self::FAILED_RETRY;
		$retryAfterOutage = $missedReplayWindow && $attempted > 0 && $now - $attempted >= self::OUTAGE_RETRY;
		$indexingRetryDue = $accepted > 0
			&& $now - $accepted >= self::INDEXING_GRACE
			&& $attempted > 0
			&& $now - $attempted >= self::INDEXING_RETRY;
		$indexingGap = $indexingRetryDue
			&& $this->status->hasRelayIndexingGap();
		$retryAfterIndexingGap = $indexingGap;

		if (!$firstRequest && !$retryFailedInitialRequest && !$retryAfterOutage && !$retryAfterIndexingGap) {
			return;
		}

		// Record the attempt before the network call, so overlapping maintenance
		// runs do not issue duplicate requests while a relay is slow.
		$this->configService->setAppValue(ConfigService::ATPROTO_CRAWL_ATTEMPTED, (string)$now);
		$this->configService->setAppValue(ConfigService::ATPROTO_CRAWL_RESULTS, '[]');
		try {
			$results = $this->relays->requestCrawl();
			$acceptedByRelay = in_array('ok', $results, true);
			$this->configService->setAppValue(ConfigService::ATPROTO_CRAWL_RESULTS, (string)json_encode($results, JSON_UNESCAPED_SLASHES));
			if ($acceptedByRelay) {
				$this->configService->setAppValue(ConfigService::ATPROTO_CRAWL_ACCEPTED, (string)$now);
			}
			$this->logger->info('Automatic Bluesky relay crawl request completed', [
				'results' => $results,
				'reason' => $firstRequest ? 'initial' : ($missedReplayWindow ? 'firehose-outage' : ($indexingGap ? 'indexing-gap' : 'retry')),
			]);
		} catch (Throwable $e) {
			$this->configService->setAppValue(ConfigService::ATPROTO_CRAWL_RESULTS, (string)json_encode(['error' => substr($e->getMessage(), 0, 200)], JSON_UNESCAPED_SLASHES));
			$this->logger->warning('Automatic Bluesky relay crawl request failed', ['exception' => $e]);
		}
	}
}
