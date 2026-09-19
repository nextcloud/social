<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\InstanceStatsRequest;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * How big the network is, and how much of it this instance has met.
 *
 * Everything else on the statistics page is counted from this server's own
 * rows, which is what makes it trustworthy and also what makes it small: a
 * person reading "31 posts, 4 followers" has no idea whether the place those
 * posts went is a village or a city. These are the numbers that say.
 *
 * They come from **fedidb.org**, which counts servers by crawling their
 * NodeInfo, and they are quoted rather than adopted: the page names the source
 * and the date, because a figure about the whole fediverse is somebody's
 * survey and not a fact this instance can check. Nothing is sent — the request
 * carries no account, no query and nothing about this instance — and the
 * answer is kept for six hours, because it moves by tenths of a percent a day.
 *
 * One number is this instance's own and is counted here: how many servers it
 * federates with. Beside the total that exists, it is the only honest answer
 * to "how far does what I write actually go".
 *
 * An administrator who would rather this instance made no outbound request for
 * a statistics page sets `network_stats` to `0`, and the section disappears
 * rather than showing zeros.
 */
class NetworkStatsService {
	/** The app value that turns the whole thing off. */
	public const CONFIG_KEY = 'network_stats';

	/** Where the figures come from, and what to call it on the page. */
	public const SOURCE_NAME = 'FediDB';
	public const SOURCE_URL = 'https://fedidb.org';
	private const ENDPOINT = 'https://api.fedidb.org/v1/stats';

	/** Long, because the network does not change between two page loads. */
	private const CACHE_TTL = 21600;
	/** And this long after a failure, so a survey that is down is left alone. */
	private const FAILURE_TTL = 1800;

	private const TIMEOUT = 4;

	private ICache $cache;

	public function __construct(
		private CurlService $curlService,
		private ConfigService $configService,
		private InstanceStatsRequest $instanceStatsRequest,
		private LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('social.network');
	}

	/**
	 * The network as somebody else counted it, plus the part of it this
	 * instance talks to.
	 *
	 * @return array{
	 *     servers: int, accounts: int, active: int, posts: int,
	 *     peers: int, measured: string, source: string, source_url: string
	 * }|null null when it is switched off, or nobody answered
	 */
	public function network(): ?array {
		if (trim((string)$this->configService->getAppValue(self::CONFIG_KEY)) === '0') {
			return null;
		}

		$counted = $this->counted();
		if ($counted === null) {
			return null;
		}

		return array_merge($counted, [
			// this instance's own number, counted here rather than quoted
			'peers' => $this->instanceStatsRequest->countRemoteDomains(),
			'source' => self::SOURCE_NAME,
			'source_url' => self::SOURCE_URL,
		]);
	}

	/**
	 * The survey's four figures, remembered.
	 *
	 * A failure is remembered too, as an empty answer: a statistics page that
	 * cannot reach the survey must not spend four seconds finding that out
	 * again for the next reader.
	 *
	 * @return array{servers: int, accounts: int, active: int, posts: int, measured: string}|null
	 */
	private function counted(): ?array {
		$cached = $this->cache->get('stats');
		if (is_string($cached)) {
			$decoded = json_decode($cached, true);

			return is_array($decoded) && $decoded !== [] ? $this->shape($decoded) : null;
		}

		try {
			$answer = $this->curlService->retrieveJson(
				'get',
				self::ENDPOINT,
				['timeout' => self::TIMEOUT, 'json_headers' => false, 'headers' => ['Accept' => 'application/json']]
			);
		} catch (Throwable $e) {
			$this->logger->debug('[NetworkStatsService] the survey did not answer', ['exception' => $e]);
			$this->cache->set('stats', '[]', self::FAILURE_TTL);

			return null;
		}

		$shaped = $this->shape($answer);
		$this->cache->set('stats', json_encode($shaped ?? []), ($shaped === null) ? self::FAILURE_TTL : self::CACHE_TTL);

		return $shaped;
	}

	/**
	 * What this app reads out of the answer, and nothing else.
	 *
	 * An answer missing the count of servers is not an answer: the figure this
	 * page exists to put next to "the servers this one talks to" is that one,
	 * and a section headed "the fediverse" showing three numbers and a blank
	 * is worse than no section.
	 *
	 * @param array<string, mixed> $answer
	 *
	 * @return array{servers: int, accounts: int, active: int, posts: int, measured: string}|null
	 */
	private function shape(array $answer): ?array {
		$servers = (int)($answer['total_instances'] ?? $answer['servers'] ?? 0);
		if ($servers < 1) {
			return null;
		}

		return [
			'servers' => $servers,
			'accounts' => (int)($answer['total_users'] ?? $answer['accounts'] ?? 0),
			'active' => (int)($answer['monthly_active_users'] ?? $answer['active'] ?? 0),
			'posts' => (int)($answer['total_statuses'] ?? $answer['posts'] ?? 0),
			'measured' => (string)($answer['last_updated_at'] ?? $answer['measured'] ?? ''),
		];
	}
}
