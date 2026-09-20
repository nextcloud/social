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
	private const SOFTWARE_ENDPOINT = 'https://api.fedidb.org/v1/software';

	/**
	 * How many platforms are named before the rest are added together.
	 *
	 * Six and a remainder: the seventh is under two per cent of accounts, and
	 * a bar with twenty slivers in it says less than one with six bands and a
	 * number for everything else.
	 */
	public const SOFTWARE_NAMED = 6;

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
	 * What the fediverse is made of: the platforms, largest first.
	 *
	 * The totals above say how big it is and nothing about what it is. "Forty
	 * thousand servers" is an abstraction; "Mastodon, Misskey, Pixelfed,
	 * PeerTube, and this is where the video ones are" is a picture of a place
	 * — and for somebody reading this page from inside a Nextcloud, the fact
	 * that the network is many kinds of software talking to each other is the
	 * whole point of it.
	 *
	 * The named few carry a share; everything else is added into one row
	 * rather than dropped, so the shares still sum to the whole and nobody has
	 * to wonder what is missing.
	 *
	 * @return array{
	 *     platforms: list<array{name: string, accounts: int, servers: int, active: int, posts: int, share: float}>,
	 *     accounts: int, source: string, source_url: string
	 * }|null
	 */
	public function software(): ?array {
		if (trim((string)$this->configService->getAppValue(self::CONFIG_KEY)) === '0') {
			return null;
		}

		$platforms = $this->platforms();
		if ($platforms === null || $platforms === []) {
			return null;
		}

		$total = array_sum(array_column($platforms, 'accounts'));
		if ($total < 1) {
			return null;
		}

		$named = array_slice($platforms, 0, self::SOFTWARE_NAMED);
		$rest = array_slice($platforms, self::SOFTWARE_NAMED);
		if ($rest !== []) {
			$named[] = [
				'name' => '',
				'accounts' => array_sum(array_column($rest, 'accounts')),
				'servers' => array_sum(array_column($rest, 'servers')),
				'active' => array_sum(array_column($rest, 'active')),
				'posts' => array_sum(array_column($rest, 'posts')),
			];
		}

		$shared = [];
		foreach ($named as $platform) {
			$accounts = (float)$platform['accounts'];
			$platform['share'] = round($accounts / (float)$total * 100.0, 1);
			$shared[] = $platform;
		}

		return [
			'platforms' => $shared,
			'accounts' => $total,
			'source' => self::SOURCE_NAME,
			'source_url' => self::SOURCE_URL,
		];
	}

	/**
	 * The platforms as the survey lists them, largest by accounts first and
	 * the empty ones left out.
	 *
	 * A platform with no accounts and no servers is one the survey knows the
	 * name of and has never met — of the seventy-odd it lists, a third are
	 * that — and a bar band of zero width with a name on it is noise.
	 *
	 * @return list<array{name: string, accounts: int, servers: int, active: int, posts: int}>|null
	 */
	private function platforms(): ?array {
		$cached = $this->cache->get('software');
		if (is_string($cached)) {
			$decoded = json_decode($cached, true);

			return (is_array($decoded) && $decoded !== []) ? $decoded : null;
		}

		try {
			$answer = $this->curlService->retrieveJson(
				'get',
				self::SOFTWARE_ENDPOINT,
				['timeout' => self::TIMEOUT, 'json_headers' => false, 'headers' => ['Accept' => 'application/json']]
			);
		} catch (Throwable $e) {
			$this->logger->debug('[NetworkStatsService] the platform list did not answer', ['exception' => $e]);
			$this->cache->set('software', '[]', self::FAILURE_TTL);

			return null;
		}

		$platforms = [];
		foreach ($answer as $row) {
			if (!is_array($row)) {
				continue;
			}

			$name = trim((string)($row['name'] ?? ''));
			$accounts = (int)($row['user_count'] ?? 0);
			if ($name === '' || $accounts < 1) {
				continue;
			}

			$platforms[] = [
				'name' => $name,
				'accounts' => $accounts,
				'servers' => (int)($row['instance_count'] ?? 0),
				'active' => (int)($row['monthly_active_users'] ?? 0),
				'posts' => (int)($row['status_count'] ?? 0),
			];
		}

		usort($platforms, static fn (array $a, array $b): int => $b['accounts'] <=> $a['accounts']);
		$this->cache->set(
			'software',
			(string)json_encode($platforms),
			($platforms === []) ? self::FAILURE_TTL : self::CACHE_TTL
		);

		return ($platforms === []) ? null : $platforms;
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
