<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\HashtagsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\HashtagDoesNotExistException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IURLGenerator;

class HashtagService {
	/** the windows the cron counts, and the one asked for when none is named */
	public const PERIODS = ['1h', '12h', '1d', '3d', '10d'];
	public const PERIOD_DEFAULT = '1d';

	public const TREND_1H = 3600;
	public const TREND_12H = 43200;
	public const TREND_1D = 86400;
	public const TREND_3D = 259200;
	public const TREND_10D = 864000;

	use TArrayTools;

	public function __construct(
		private HashtagsRequest $hashtagsRequest,
		private StreamRequest $streamRequest,
		private IURLGenerator $urlGenerator,
		private ConfigService $configService,
		private MiscService $miscService,
	) {
	}

	/*
	 * note: trend is:
	 * [
	 *   '1h' => x,
	 *   '12h' => x,
	 *   '1d' => x,
	 *   '3d' => x,
	 *   '10d' => x
	 * ]
	 */

	/**
	 * @return int
	 * @throws DateTimeException
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 */
	public function manageHashtags(): int {
		// only the rows that claim a trend: those are the only ones this can
		// have anything to clear, and the alternative was reading every hashtag
		// the instance has ever seen on every cron run
		$current = $this->hashtagsRequest->getWithAnyTrend();

		$time = time();
		$hashtags = [
			'1h' => $this->streamRequest->countHashtagsSince($time - self::TREND_1H),
			'12h' => $this->streamRequest->countHashtagsSince($time - self::TREND_12H),
			'1d' => $this->streamRequest->countHashtagsSince($time - self::TREND_1D),
			'3d' => $this->streamRequest->countHashtagsSince($time - self::TREND_3D),
			'10d' => $this->streamRequest->countHashtagsSince($time - self::TREND_10D)
		];

		$count = 0;
		$formatted = $this->formatTrend($hashtags);

		// a hashtag that has fallen out of the widest window keeps whatever it
		// last scored otherwise, and stays "trending" for good
		foreach ($current as $item) {
			$hashtag = $this->get('hashtag', $item, '');
			if ($hashtag !== '' && !array_key_exists($hashtag, $formatted)
				&& array_sum($this->getArray('trend', $item, [])) > 0) {
				$formatted[$hashtag] = array_fill_keys(self::PERIODS, 0);
			}
		}

		foreach ($formatted as $hashtag => $trend) {
			try {
				$known = $this->getFromList($current, $hashtag);
				if ($this->getArray('trend', $known, []) === $trend
					&& $this->getArray('counters', $known, []) === $trend) {
					// nothing moved for this hashtag since the last pass, and
					// the sortable columns agree with the JSON. The second half
					// matters on an instance upgraded from a version that had
					// no columns: its JSON is right and its columns are zero,
					// and on a quiet instance the counts never move again — so
					// without this the row would stay out of the trends for
					// good, because getTrending() reads the columns.
					continue;
				}

				$this->hashtagsRequest->update($hashtag, $trend);
			} catch (HashtagDoesNotExistException $e) {
				$this->hashtagsRequest->save($hashtag, $trend);
			}
			$count++;
		}

		return $count;
	}

	/**
	 * @param string $hashtag
	 *
	 * @return array
	 * @throws HashtagDoesNotExistException
	 */
	public function getHashtag(string $hashtag): array {
		// stored without it: the tags come from social_stream_tag, and
		// Note::fillHashtags() strips the '#' before either table sees one.
		// Adding it back here meant this lookup could never match, so the
		// exact-match half of a hashtag search always came up empty.
		return $this->hashtagsRequest->getHashtag(ltrim(trim($hashtag), '#'));
	}

	/**
	 * One hashtag as Mastodon's `Tag` entity: `name`, `url`, `history`, and
	 * `following` when there is somebody to answer that for.
	 *
	 * The single place this shape is built, so that the trends list, the tag
	 * lookup and the follow/unfollow answers cannot drift apart — a client
	 * that gets a `Tag` without `history` from one route and with it from
	 * another has to special-case this server.
	 *
	 * `history` carries one bucket, for the window that was asked for, and its
	 * `accounts` is always `0`: this instance counts uses, not distinct
	 * accounts. A hashtag nobody has posted has no bucket at all rather than a
	 * zero, because a zero is a claim about a day and this is the absence of
	 * one.
	 *
	 * @param string $hashtag with no leading '#', as the tables store it
	 * @param bool|null $following null leaves the key out, for a route with no
	 *                             viewer to answer it for
	 */
	public function tagEntity(
		string $hashtag, ?bool $following = null, string $period = self::PERIOD_DEFAULT,
	): array {
		$tag = [
			'name' => $hashtag,
			'url' => $this->urlGenerator->linkToRouteAbsolute(
				'social.Navigation.timeline', ['path' => 'tags/' . $hashtag]
			),
			'history' => $this->tagHistory($hashtag, $period),
		];

		if ($following !== null) {
			$tag['following'] = $following;
		}

		return $tag;
	}

	/**
	 * The `history` of a Tag entity: one bucket for one window, or none.
	 */
	private function tagHistory(string $hashtag, string $period): array {
		if (!in_array($period, self::PERIODS, true)) {
			$period = self::PERIOD_DEFAULT;
		}

		try {
			$known = $this->hashtagsRequest->getHashtag($hashtag);
		} catch (HashtagDoesNotExistException $e) {
			return [];
		}

		return [
			[
				'day' => (string)strtotime('today midnight'),
				'uses' => (string)(int)($this->getArray('trend', $known, [])[$period] ?? 0),
				'accounts' => '0',
			]
		];
	}

	/**
	 * @param string $hashtag
	 * @param bool $all
	 *
	 * @return array
	 */
	public function searchHashtags(string $hashtag, bool $all = false): array {
		return $this->hashtagsRequest->searchHashtags($hashtag, $all);
	}

	/**
	 * The hashtags used most within one of the windows the cron already
	 * counts, most used first — ordered and cut by the database.
	 *
	 * @param int $limit how many to return
	 * @param string $period one of 1h, 12h, 1d, 3d, 10d
	 *
	 * @return array[] [['hashtag' => string, 'trend' => array], …]
	 */
	public function getTrending(int $limit = 10, string $period = self::PERIOD_DEFAULT): array {
		if (!in_array($period, self::PERIODS, true)) {
			$period = self::PERIOD_DEFAULT;
		}

		return $this->hashtagsRequest->getTrending($period, $limit);
	}

	/**
	 * @param array $hashtags
	 *
	 * @return array
	 */
	private function formatTrend(array $hashtags): array {
		$trends = [];
		foreach (end($hashtags) as $hashtag => $count) {
			$trends[$hashtag] = [];
		}

		$all = array_keys($trends);
		$periods = array_keys($hashtags);
		foreach ($all as $hashtag) {
			foreach ($periods as $period) {
				$count = $this->countFromList($hashtags[$period], $hashtag);
				$trends[$hashtag][$period] = $count;
			}
		}

		return $trends;
	}

	/**
	 * @param array $list
	 * @param string $hashtag
	 *
	 * @return int
	 */
	private function countFromList(array $list, string $hashtag): int {
		foreach ($list as $key => $count) {
			if ($key === $hashtag) {
				return $count;
			}
		}

		return 0;
	}

	/**
	 * @param array $list
	 * @param string $hashtag
	 *
	 * @return array
	 * @throws HashtagDoesNotExistException
	 */
	private function getFromList(array $list, string $hashtag): array {
		foreach ($list as $item) {
			if ($this->get('hashtag', $item, '') === $hashtag) {
				return $item;
			}
		}

		throw new HashtagDoesNotExistException();
	}
}
