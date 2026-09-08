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
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCA\Social\Tools\Traits\TArrayTools;

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

	private HashtagsRequest $hashtagsRequest;

	private StreamRequest $streamRequest;

	private ConfigService $configService;

	private MiscService $miscService;

	/**
	 * ImportService constructor.
	 *
	 * @param HashtagsRequest $hashtagsRequest
	 * @param StreamRequest $streamRequest
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		HashtagsRequest $hashtagsRequest, StreamRequest $streamRequest,
		ConfigService $configService,
		MiscService $miscService,
	) {
		$this->hashtagsRequest = $hashtagsRequest;
		$this->streamRequest = $streamRequest;
		$this->configService = $configService;
		$this->miscService = $miscService;
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
		$current = $this->hashtagsRequest->getAll();

		$time = time();
		$hashtags = [
			'1h' => $this->getTrendSince($time - self::TREND_1H),
			'12h' => $this->getTrendSince($time - self::TREND_12H),
			'1d' => $this->getTrendSince($time - self::TREND_1D),
			'3d' => $this->getTrendSince($time - self::TREND_3D),
			'10d' => $this->getTrendSince($time - self::TREND_10D)
		];

		$count = 0;
		$formatted = $this->formatTrend($hashtags);
		foreach ($formatted as $hashtag => $trend) {
			$count++;
			try {
				$this->getFromList($current, $hashtag);
				$this->hashtagsRequest->update($hashtag, $trend);
			} catch (HashtagDoesNotExistException $e) {
				$this->hashtagsRequest->save($hashtag, $trend);
			}
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
		if (substr($hashtag, 0, 1) !== '#') {
			$hashtag = '#' . $hashtag;
		}

		return $this->hashtagsRequest->getHashtag($hashtag);
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
	 * @param int $timestamp
	 *
	 * @return int[]
	 * @throws DateTimeException
	 * @psalm-return array<int>
	 */
	private function getTrendSince(int $timestamp): array {
		$result = [];

		$notes = $this->streamRequest->getNoteSince($timestamp);
		foreach ($notes as $note) {
			/** @var Note $note */
			foreach ($note->getHashtags() as $hashtag) {
				if (array_key_exists($hashtag, $result)) {
					$result[$hashtag]++;
				} else {
					$result[$hashtag] = 1;
				}
			}
		}

		return $result;
	}

	/**
	 * The hashtags used most within one of the windows the cron already
	 * counts, most used first.
	 *
	 * The counts live in a JSON column, which no supported database can be
	 * asked to sort on portably, so the rows are ordered here. The table holds
	 * one row per hashtag the instance has ever seen — small enough that this
	 * is cheaper than a schema for it.
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

		$hashtags = array_filter(
			$this->hashtagsRequest->getAll(),
			static fn (array $hashtag): bool => (int)($hashtag['trend'][$period] ?? 0) > 0
		);

		usort(
			$hashtags,
			static fn (array $first, array $second): int => ((int)($second['trend'][$period] ?? 0))
				<=> ((int)($first['trend'][$period] ?? 0))
		);

		return array_slice(array_values($hashtags), 0, max(1, $limit));
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
