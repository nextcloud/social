<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\TrendsRequest;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\TrendingLink;
use OCA\Social\Model\StreamCard;

/**
 * Trending statuses and trending links.
 *
 * The hashtag trends next door keep their counts in `social_hashtag`, written
 * by a cron, because counting hashtag uses means walking a table with a row
 * per tag per post. These two need no such table: a status trend is a grouped
 * count of `social_action` rows against an indexed column and a link trend a
 * grouped count of `social_stream_card` rows, so they are computed when they
 * are asked for and cannot go stale between cron runs.
 *
 * The windows are `HashtagService::PERIODS` and the default is its default, so
 * `/api/v1/trends/tags`, `/trends/statuses` and `/trends/links` all report on
 * the same stretch of time when a client asks all three — which is what a
 * "trending" page does.
 */
class TrendService {
	/** @var array<string, int> the windows of HashtagService, in seconds */
	private const WINDOWS = [
		'1h' => HashtagService::TREND_1H,
		'12h' => HashtagService::TREND_12H,
		'1d' => HashtagService::TREND_1D,
		'3d' => HashtagService::TREND_3D,
		'10d' => HashtagService::TREND_10D,
	];

	/** What Mastodon defaults and caps both trend routes at. */
	public const LIMIT = 20;
	public const MAX_LIMIT = 40;

	public function __construct(
		private TrendsRequest $trendsRequest,
	) {
	}

	/**
	 * The most interacted-with public statuses of the window.
	 *
	 * @return Stream[]
	 */
	public function trendingStatuses(string $period, int $limit, int $offset, bool $onlyMedia = false): array {
		$nids = $this->trendsRequest->trendingStatusNids(
			$this->since($period), $this->limit($limit), max(0, $offset), $onlyMedia
		);

		return $this->trendsRequest->statusesByNids($nids);
	}

	/**
	 * The public posts carrying one link, newest first.
	 *
	 * Mastodon's link timeline: what a reader gets by tapping a trending link
	 * rather than following it off the instance. The links themselves were
	 * already served at `/api/v1/trends/links`, so the data was here and the
	 * timeline that reads it was not.
	 *
	 * @return Stream[]
	 */
	public function linkTimeline(string $url, int $limit, int $maxId = 0, int $minId = 0): array {
		$url = trim($url);
		if ($url === '') {
			return [];
		}

		return $this->trendsRequest->statusesByNids(
			$this->trendsRequest->statusNidsForUrl($url, $limit, $maxId, $minId)
		);
	}

	/**
	 * The links most often attached to a public status in the window.
	 *
	 * A link whose preview row has gone — the post it was fetched for was
	 * deleted, taking the card with it, while another post still carries the
	 * same url — is still a trending link, and is answered with a card holding
	 * the url alone rather than dropped: a client renders that as a bare link,
	 * which is true, where dropping it would make the list disagree with its
	 * own counts.
	 *
	 * @return TrendingLink[]
	 */
	public function trendingLinks(string $period, int $limit, int $offset): array {
		$counted = $this->trendsRequest->trendingLinks(
			$this->since($period), $this->limit($limit), max(0, $offset)
		);
		if ($counted === []) {
			return [];
		}

		$cards = $this->trendsRequest->cardsByUrls(array_column($counted, 'url'));

		$links = [];
		foreach ($counted as $link) {
			$card = $cards[$link['url']] ?? (new StreamCard())->setUrl($link['url']);
			$links[] = new TrendingLink($card, $link['shares']);
		}

		return $links;
	}

	/**
	 * The start of the window, as a unix time. An unknown period falls back to
	 * the default rather than being refused, which is what
	 * `HashtagService::getTrending()` does with the same parameter.
	 */
	private function since(string $period): int {
		return time() - (self::WINDOWS[$period] ?? self::WINDOWS[HashtagService::PERIOD_DEFAULT]);
	}

	private function limit(int $limit): int {
		return max(1, min(self::MAX_LIMIT, $limit));
	}
}
