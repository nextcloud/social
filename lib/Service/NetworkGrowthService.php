<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Whether the network is growing, and how fast.
 *
 * `NetworkStatsService` says how big the fediverse is today. A single number
 * says nothing about the thing a person actually wants to know when they are
 * deciding whether to write here — whether this is somewhere more people are
 * arriving or somewhere they are leaving — and no amount of precision about
 * today answers it.
 *
 * **A second source, named as such.** FediDB publishes a snapshot and no
 * history at all, so the shape over time comes from **Fediverse Observer**,
 * whose public GraphQL API has a monthly series back to 2017. The two do not
 * agree — they crawl different servers and count dormant accounts differently,
 * and Observer's account total is more than twice FediDB's — so the numbers
 * are never mixed: the current figures stay FediDB's and the series stays
 * Observer's, each with its own name beside it. A page that averaged them
 * would produce a number neither of them would stand behind and nobody could
 * check.
 *
 * Kept for a day, because a monthly series changes monthly, and switched off
 * by the same app value as the figures above it: an administrator who does not
 * want this instance making outbound requests for a statistics page means both.
 */
class NetworkGrowthService {
	public const SOURCE_NAME = 'Fediverse Observer';
	public const SOURCE_URL = 'https://fediverse.observer';
	private const ENDPOINT = 'https://api.fediverse.observer/';

	/**
	 * How many months the page shows.
	 *
	 * Two years: enough for a shape and a year-over-year comparison, short
	 * enough that the 2022 spike does not flatten everything since into a
	 * line. The series itself goes back to 2017 and anybody who wants that can
	 * follow the link.
	 */
	public const MONTHS = 24;

	/**
	 * A month-over-month step this large is not something a federated network
	 * does; it is the survey changing what it crawls.
	 *
	 * Observer's own series shows it plainly: 25,000 servers in January 2026
	 * and 41,000 in March, 20 million accounts in December 2025 and 36 million
	 * in February. Fifteen million people did not join the fediverse that
	 * quarter — the crawler reached servers it had not reached before. A
	 * comparison across such a step measures the survey and not the network,
	 * so the app will not print one.
	 */
	private const COVERAGE_STEP = 25.0;

	/** A monthly series changes monthly. */
	private const CACHE_TTL = 86400;
	/** And this long after a failure, so a source that is down is left alone. */
	private const FAILURE_TTL = 3600;

	private const TIMEOUT = 6;

	private ICache $cache;

	public function __construct(
		private CurlService $curlService,
		private ConfigService $configService,
		private LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('social.network');
	}

	/**
	 * The last two years, oldest first, with what changed over the last month
	 * and the last year.
	 *
	 * Oldest first because that is the order a chart is drawn in, and the page
	 * should not have to reverse somebody else's answer.
	 *
	 * @return array{
	 *     months: list<array{month: string, servers: int, accounts: int, active: int, posts: int}>,
	 *     change: array{month: array<string, float>, year: array<string, float>},
	 *     coverage_changed: bool, source: string, source_url: string
	 * }|null null when it is switched off, or nobody answered
	 */
	public function growth(): ?array {
		if (trim((string)$this->configService->getAppValue(NetworkStatsService::CONFIG_KEY)) === '0') {
			return null;
		}

		$months = $this->months();
		if ($months === null || count($months) < 2) {
			// one point is not a trend, and drawing it as one would be the page
			// claiming to know something it does not
			return null;
		}

		$year = $this->changeOver($months, 12);
		$steady = $this->steadyOver($months, 12);

		return [
			'months' => $months,
			'change' => [
				'month' => $this->changeOver($months, 1),
				// withheld rather than shown with a caveat nobody reads: a
				// year-over-year figure across a coverage change describes the
				// survey, not the fediverse
				'year' => $steady ? $year : [],
			],
			// so the page can say why the year is missing, rather than leaving
			// a reader to wonder whether it is a bug
			'coverage_changed' => !$steady,
			'source' => self::SOURCE_NAME,
			'source_url' => self::SOURCE_URL,
		];
	}

	/**
	 * The series, remembered.
	 *
	 * @return list<array{month: string, servers: int, accounts: int, active: int, posts: int}>|null
	 */
	private function months(): ?array {
		$cached = $this->cache->get('growth');
		if (is_string($cached)) {
			$decoded = json_decode($cached, true);

			return (is_array($decoded) && $decoded !== []) ? $decoded : null;
		}

		try {
			$answer = $this->curlService->retrieveJson(
				'post',
				self::ENDPOINT,
				[
					'timeout' => self::TIMEOUT,
					'json_headers' => false,
					'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
					'body' => (string)json_encode(['query' => $this->query()]),
				]
			);
		} catch (Throwable $e) {
			$this->logger->debug('[NetworkGrowthService] the series did not answer', ['exception' => $e]);
			$this->cache->set('growth', '[]', self::FAILURE_TTL);

			return null;
		}

		$months = $this->shape($answer);
		$this->cache->set(
			'growth',
			(string)json_encode($months ?? []),
			($months === null) ? self::FAILURE_TTL : self::CACHE_TTL
		);

		return $months;
	}

	/** Everything this app reads, and nothing else. */
	private function query(): string {
		return '{ monthlystats(softwarename: "all") { total_users total_servers total_posts '
			. 'total_active_users_monthly date_checked } }';
	}

	/**
	 * What this app reads out of the answer.
	 *
	 * The series arrives newest first and is turned round here; a row with no
	 * servers on it is a month the crawler has no answer for rather than a
	 * month the fediverse did not exist, and is dropped rather than drawn as a
	 * cliff.
	 *
	 * @param array<string, mixed> $answer
	 *
	 * @return list<array{month: string, servers: int, accounts: int, active: int, posts: int}>|null
	 */
	private function shape(array $answer): ?array {
		$rows = $answer['data']['monthlystats'] ?? null;
		if (!is_array($rows) || $rows === []) {
			return null;
		}

		$months = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$servers = (int)($row['total_servers'] ?? 0);
			$month = substr((string)($row['date_checked'] ?? ''), 0, 7);
			if ($servers < 1 || !preg_match('/^\d{4}-\d{2}$/', $month)) {
				continue;
			}

			$months[$month] = [
				'month' => $month,
				'servers' => $servers,
				'accounts' => (int)($row['total_users'] ?? 0),
				'active' => (int)($row['total_active_users_monthly'] ?? 0),
				'posts' => (int)($row['total_posts'] ?? 0),
			];
		}

		if ($months === []) {
			return null;
		}

		ksort($months);

		return array_slice(array_values($months), -self::MONTHS);
	}

	/**
	 * Whether the series over this window is the same survey throughout.
	 *
	 * A step of more than a quarter from one month to the next is the crawler
	 * changing what it reaches; see `COVERAGE_STEP`. Only the two figures that
	 * move with coverage are looked at — the active and post counts swing for
	 * ordinary reasons, and holding them to this would withhold a comparison
	 * over a busy month.
	 *
	 * @param list<array{month: string, servers: int, accounts: int, active: int, posts: int}> $months
	 */
	private function steadyOver(array $months, int $back): bool {
		$last = count($months) - 1;
		$first = $last - $back;
		if ($first < 0) {
			return false;
		}

		for ($i = $first; $i < $last; $i++) {
			foreach (['servers', 'accounts'] as $key) {
				$was = (int)$months[$i][$key];
				$now = (int)$months[$i + 1][$key];
				if ($was > 0 && abs((float)($now - $was)) / (float)$was * 100.0 > self::COVERAGE_STEP) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * What changed between the last month in the series and the one this many
	 * months before it, as a fraction.
	 *
	 * A fraction rather than a difference, because the four figures are orders
	 * of magnitude apart and "up 3%" is the sentence a reader wants from all
	 * four of them. Absent where the series does not reach back that far: a
	 * year-over-year figure computed over eight months would be a number with
	 * a wrong name on it.
	 *
	 * @param list<array{month: string, servers: int, accounts: int, active: int, posts: int}> $months
	 *
	 * @return array<string, float>
	 */
	private function changeOver(array $months, int $back): array {
		$last = count($months) - 1;
		$first = $last - $back;
		if ($last < 0 || $first < 0) {
			return [];
		}

		$latest = $months[$last];
		$earlier = $months[$first];

		$change = [];
		foreach (['servers', 'accounts', 'active', 'posts'] as $key) {
			$was = (int)$earlier[$key];
			if ($was > 0) {
				$moved = (float)((int)$latest[$key] - $was);
				$change[$key] = round($moved / (float)$was * 100.0, 1);
			}
		}

		return $change;
	}
}
