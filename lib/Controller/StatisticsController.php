<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Service\AccountService;
use OCA\Social\Service\NetworkGrowthService;
use OCA\Social\Service\NetworkStatsService;
use OCA\Social\Service\StatisticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The reader's own numbers.
 *
 * A session route rather than a client-API one, for the same reason the
 * migration routes are: it is for the person sitting in front of the browser,
 * and an account's whole history of engagement is not something a third-party
 * token should be handed. It is also only ever about the caller — there is no
 * account parameter, so there is nothing to point at somebody else.
 *
 * Rate limited because the answer is a walk over the account's posts rather
 * than a lookup, and a reload loop should not be able to spend that repeatedly.
 */
class StatisticsController extends Controller {
	public function __construct(
		IRequest $request,
		private ?string $userId,
		private AccountService $accountService,
		private StatisticsService $statisticsService,
		private NetworkStatsService $networkStatsService,
		private NetworkGrowthService $networkGrowthService,
		private LoggerInterface $logger,
	) {
		parent::__construct('social', $request);
	}

	/**
	 * @param int $days the window, one of `StatisticsService::WINDOWS`
	 * @param bool $fresh count again rather than read the cached answer
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 90, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statistics')]
	public function statistics(int $days = 0, bool $fresh = false): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$actor = $this->accountService->getActorFromUserId($this->userId);

			$statistics = $this->statisticsService->cachedForAccount($actor, $days, $fresh);
			// the size of the place all this went out to, which nothing
			// counted here can say. Null when it is switched off or the
			// survey did not answer, and the page leaves the section out
			// rather than drawing zeros
			$statistics['network'] = $this->networkStatsService->network();
			// and whether that place is growing, which is the question a
			// single number cannot answer. A second source, named as such: the
			// snapshot above publishes no history at all
			$statistics['growth'] = $this->networkGrowthService->growth();
			// and what it is made of: "forty thousand servers" is an
			// abstraction, and the list of platforms is a picture of a place
			$statistics['software'] = $this->networkStatsService->software();

			return new DataResponse($statistics, Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->warning('could not build the statistics', [
				'userId' => $this->userId, 'exception' => $e,
			]);

			return new DataResponse(
				['error' => 'could not build the statistics'], Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	/**
	 * The same figures as a file, for somebody who wants to keep them.
	 *
	 * A page somebody checks every month is a page they will want a record of
	 * — and this app stores none of it, so the only copy is the one they take.
	 * CSV because that is what a spreadsheet opens; the whole answer is in
	 * `json` for anybody who wants the shape rather than the summary.
	 *
	 * @param int $days the window, one of `StatisticsService::WINDOWS`
	 * @param string $format `csv` or `json`
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statistics/export')]
	public function export(int $days = 0, string $format = 'csv'): Response {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$actor = $this->accountService->getActorFromUserId($this->userId);
			$statistics = $this->statisticsService->cachedForAccount($actor, $days);
		} catch (Throwable $e) {
			$this->logger->warning('could not build the statistics', ['exception' => $e]);

			return new DataResponse(
				['error' => 'could not build the statistics'], Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		$stamp = gmdate('Y-m-d');
		if (strtolower($format) === 'json') {
			return $this->attachment(
				(string)json_encode($statistics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
				'application/json',
				'social-statistics-' . $stamp . '.json'
			);
		}

		return $this->attachment(
			$this->asCsv($statistics),
			'text/csv',
			'social-statistics-' . $stamp . '.csv'
		);
	}

	/** A file the browser saves rather than draws. */
	private function attachment(string $body, string $type, string $name): Response {
		$response = new DataDownloadResponse($body, $name, $type);
		$response->cacheFor(0);

		return $response;
	}

	/**
	 * The figures worth putting in a spreadsheet, one per row.
	 *
	 * Flattened deliberately rather than dumped: a CSV of a nested structure
	 * is a worse JSON file. What goes in is what somebody would chart — the
	 * totals, the rates, and the per-month series.
	 *
	 * @param array<string, mixed> $statistics
	 */
	private function asCsv(array $statistics): string {
		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			return '';
		}

		fputcsv($handle, ['section', 'name', 'value'], ',', '"', '');

		$rows = [
			['account', 'handle', $statistics['account']['acct'] ?? ''],
			['account', 'followers', $statistics['account']['followers'] ?? 0],
			['account', 'following', $statistics['account']['following'] ?? 0],
			['window', 'days', $statistics['window']['days'] ?? 0],
			['window', 'posts counted', $statistics['window']['counted'] ?? 0],
			['window', 'capped', ($statistics['window']['capped'] ?? false) ? 'yes' : 'no'],
		];

		foreach (['posts', 'engagement', 'rates', 'visibility', 'consistency', 'media'] as $section) {
			foreach ($statistics[$section] ?? [] as $name => $value) {
				if (is_scalar($value)) {
					$rows[] = [$section, (string)$name, $value];
				}
			}
		}

		foreach (['by_month' => 'posts', 'engagement_by_month' => 'engagement'] as $key => $label) {
			foreach ($statistics[$key] ?? [] as $month => $value) {
				$rows[] = ['by month', $month . ' ' . $label, $value];
			}
		}

		foreach (['originals', 'replies', 'boosts'] as $kind) {
			foreach ($statistics['activity'][$kind] ?? [] as $month => $value) {
				$rows[] = ['activity', $month . ' ' . $kind, $value];
			}
		}

		foreach (['inbound', 'outbound'] as $direction) {
			foreach ($statistics['partners'][$direction] ?? [] as $partner) {
				$rows[] = [
					'partners ' . $direction,
					(string)($partner['account'] ?? ''),
					$partner['replies'] ?? 0,
				];
			}
		}

		foreach (['languages', 'domains', 'hashtags'] as $section) {
			foreach ($statistics[$section] ?? [] as $entry) {
				$rows[] = [$section, (string)($entry['name'] ?? ''), $entry['count'] ?? 0];
			}
		}

		foreach ($rows as $row) {
			fputcsv($handle, $row, ',', '"', '');
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}
}
