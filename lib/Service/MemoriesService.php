<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;

/**
 * What the reader wrote on this day in years gone by.
 *
 * An internal social feed is a company's memory, and almost all of it is
 * unreachable the moment it scrolls off the timeline: a post is found again
 * only by somebody who remembers enough of it to search for it. This is the
 * one view that surfaces old posts without being asked — the same day, a year
 * or more ago.
 *
 * **Only ever the caller's own posts.** Not a privacy convenience but the
 * whole design: because it is the reader's own, it can include their
 * followers-only and direct posts without asking who is allowed to see what.
 * The controller must pass the viewer's own actor and nothing else, and the
 * query it uses does not filter by audience.
 *
 * **Anniversaries, not a window.** A post from "about a year ago" is not a
 * memory, it is a random old post. Each year back is looked up as its own
 * calendar day in the reader's own timezone, so the 4th of March finds the 4th
 * of March and a post written at 23:50 does not belong to the next day.
 */
class MemoriesService {
	/** How many years back to look. */
	private const YEARS = 5;

	/** How many posts one year may contribute. */
	private const PER_YEAR = 3;

	/** How many come back in all, however many years had something. */
	private const TOTAL = 6;

	public function __construct(
		private StreamRequest $streamRequest,
	) {
	}

	/**
	 * The reader's own posts from this day in previous years, newest first.
	 *
	 * @param Person $actor the caller's own actor, never anybody else's
	 * @param DateTimeZone $timezone the reader's timezone; a "day" is theirs,
	 *                               not the server's
	 * @param DateTimeImmutable|null $today injectable so this can be tested
	 * @return Stream[]
	 */
	public function onThisDay(Person $actor, DateTimeZone $timezone, ?DateTimeImmutable $today = null): array {
		$actorId = $actor->getId();
		if ($actorId === '') {
			return [];
		}

		$today ??= new DateTimeImmutable('now', $timezone);
		$today = $today->setTimezone($timezone);

		$memories = [];
		for ($years = 1; $years <= self::YEARS; $years++) {
			$from = $today->modify(sprintf('-%d years', $years))->setTime(0, 0, 0);

			// modify() on the 29th of February lands on the 1st of March in a
			// year that has no 29th, which is a different day's posts. A leap
			// day is remembered on leap years only.
			if ($from->format('m-d') !== $today->format('m-d')) {
				continue;
			}

			$until = $from->modify('+1 day');

			foreach ($this->streamRequest->getByAuthorBetween(
				$actorId,
				$from->getTimestamp(),
				$until->getTimestamp(),
				self::PER_YEAR,
				ACore::FORMAT_LOCAL
			) as $post) {
				$memories[] = $post;
			}
		}

		usort(
			$memories,
			static fn (Stream $a, Stream $b): int => $b->getPublishedTime() <=> $a->getPublishedTime()
		);

		return array_slice($memories, 0, self::TOTAL);
	}
}
