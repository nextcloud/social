<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * The few numbers that say what an account is like, for the top of its
 * profile.
 *
 * A profile used to be a name, a picture and three counts. The counts say how
 * much of something there is and nothing about what the account does with it:
 * "412 posts" reads the same for somebody who wrote them all last week and
 * somebody who has been here since 2019. What is added here is the shape of
 * that history — when it started, how the last few weeks went, and what the
 * account keeps writing about.
 *
 * **Local accounts only.** For a remote account this instance holds whatever
 * happened to arrive: the posts of people somebody here follows, from whenever
 * they started following. A chart drawn from that would show a quiet 2019 for
 * an account that was busy, because this instance was not listening yet, and
 * there is no way to tell that apart from an account that really was quiet. A
 * partial history presented as a history is a lie, so remote accounts get
 * nothing rather than something wrong.
 *
 * **Public posts only**, which is also what the profile timeline shows. Anything
 * else would be a different chart for every viewer, and counting posts the
 * viewer cannot see would tell them those posts exist.
 */
class ProfileHighlightsService {
	/** How many weeks the little chart covers. */
	public const WEEKS = 12;

	/** A week, in seconds. */
	private const WEEK = 7 * 24 * 3600;

	/**
	 * How many posts the chart will read at most.
	 *
	 * Twelve weeks of an ordinary account is far below this. It is here so an
	 * account that posts hundreds of times a day cannot turn a profile view
	 * into an unbounded query; the chart is then drawn from the most recent
	 * posts, which is the part of the window it is about anyway.
	 */
	private const MAX_POSTS = 5000;

	/** How many hashtags are named. */
	private const TOP_HASHTAGS = 3;

	/**
	 * How far back the "posting since" and the hashtags look.
	 *
	 * A year rather than everything: what an account has been writing about
	 * lately is the useful answer, and a tag somebody used a hundred times in
	 * 2019 and never since is not what they are about now.
	 */
	private const HASHTAG_WINDOW = 365 * 24 * 3600;

	public function __construct(
		private StreamRequest $streamRequest,
	) {
	}

	/**
	 * @param Person $actor whose profile is being looked at
	 * @param int $now unix time, injectable so the buckets can be tested
	 * @return array{
	 *     available: bool,
	 *     since: int,
	 *     weeks: list<int>,
	 *     week_starts: int,
	 *     hashtags: list<array{name: string, count: int}>
	 * }
	 */
	public function forActor(Person $actor, int $now = 0): array {
		$now = ($now > 0) ? $now : time();

		if (!$actor->isLocal()) {
			return $this->nothing($now);
		}

		$actorId = $actor->getId();
		if ($actorId === '') {
			return $this->nothing($now);
		}

		// buckets run back from the start of the current week, so the last one
		// is the week in progress rather than a part-week ending right now
		$currentWeekStart = $now - ($now % self::WEEK);
		$from = $currentWeekStart - (self::WEEKS - 1) * self::WEEK;

		$weeks = array_fill(0, self::WEEKS, 0);
		foreach ($this->streamRequest->publishedTimesByAuthor($actorId, $from, self::MAX_POSTS) as $time) {
			$offset = $time - $from;

			// Both ends of the window are checked here, in seconds, rather
			// than on the bucket number below. Before: intdiv() truncates
			// towards zero, so a post one second older than `$from` would
			// give bucket 0 and be counted into the oldest week instead of
			// being dropped. After: a post published in the moment between
			// the query and this loop is newer than the last bucket.
			if ($offset < 0 || $offset >= self::WEEKS * self::WEEK) {
				continue;
			}

			$weeks[intdiv($offset, self::WEEK)]++;
		}

		return [
			'available' => true,
			'since' => $this->firstPostTime($actor),
			'weeks' => $weeks,
			'week_starts' => $from,
			'hashtags' => $this->streamRequest->topHashtagsByAuthor(
				$actorId, $now - self::HASHTAG_WINDOW, self::TOP_HASHTAGS
			),
		];
	}

	/**
	 * When this account started posting here.
	 *
	 * The actor's own creation date, not its first post: an account that has
	 * deleted everything it ever wrote has still been here since it was made,
	 * and reading the oldest surviving post would say otherwise.
	 *
	 * @return int unix time, or 0 when the actor carries no creation date
	 */
	private function firstPostTime(Person $actor): int {
		return max(0, $actor->getCreation());
	}

	/**
	 * The answer for an account this instance cannot honestly chart.
	 *
	 * `available` is false rather than the arrays being empty, so the profile
	 * can leave the section out entirely instead of drawing twelve zeroes —
	 * which would say "this account posted nothing", which is the one thing
	 * this does not know.
	 *
	 * @return array{
	 *     available: bool,
	 *     since: int,
	 *     weeks: list<int>,
	 *     week_starts: int,
	 *     hashtags: list<array{name: string, count: int}>
	 * }
	 */
	private function nothing(int $now): array {
		return [
			'available' => false,
			'since' => 0,
			'weeks' => [],
			'week_starts' => $now,
			'hashtags' => [],
		];
	}
}
