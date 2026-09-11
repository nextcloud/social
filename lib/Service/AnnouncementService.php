<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\AnnouncementsRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\Announcement;

/**
 * Mastodon's announcements: the notice an admin posts to the whole instance,
 * and the dismissal that makes it read for one account.
 *
 * Two surfaces meet here and they are not the same read. A client is answered
 * with the announcements that apply *now* — the window is a predicate of the
 * query, so nothing has to run for one to start or stop — each carrying
 * whether that account has dismissed it. The administration page is answered
 * with every announcement there is, including one that has not started and one
 * that has run out, because those are exactly the ones an admin needs to see
 * to act on.
 *
 * Dismissal is per account and stored as such: `read` is filled in from the
 * account being answered and from nothing else, so one account's read state is
 * never another's. Dismissing does not hide the announcement — Mastodon keeps
 * serving it and flips `read`, which is what lets a client show the notice
 * with its unread mark gone rather than have it vanish mid-read.
 *
 * There is no edit route. An announcement is a thing people have already read;
 * changing one under them would leave some accounts having dismissed a notice
 * that now says something else, and Mastodon's own answer to that — bumping
 * `updated_at` and leaving the dismissals — is worse than posting a new one.
 * `updated_at` is therefore always `published_at`, which is what the column
 * holds.
 */
class AnnouncementService {
	public function __construct(
		private AnnouncementsRequest $announcementsRequest,
	) {
	}

	/**
	 * The announcements that apply, oldest window first, each carrying whether
	 * this account has dismissed it.
	 *
	 * Ordered the way Mastodon orders them — by when the announcement takes
	 * effect, which is its start when it has one and its publication when it
	 * does not. That is a COALESCE of two columns and it is done here rather
	 * than in SQL: the set is the handful of announcements that are live at
	 * this moment, and a sort of a handful of rows is not worth an expression
	 * four databases have to agree on.
	 *
	 * @return Announcement[]
	 */
	public function active(string $actorId, ?int $now = null): array {
		$announcements = $this->announcementsRequest->getActive($now);
		if ($announcements === []) {
			return [];
		}

		$dismissed = $this->announcementsRequest->dismissedBy(
			$actorId,
			array_map(static fn (Announcement $announcement): int => $announcement->getId(), $announcements)
		);

		foreach ($announcements as $announcement) {
			$announcement->setRead(in_array($announcement->getId(), $dismissed, true));
		}

		usort(
			$announcements,
			static fn (Announcement $a, Announcement $b): int
				=> [self::effectiveStart($a), $a->getId()] <=> [self::effectiveStart($b), $b->getId()]
		);

		return $announcements;
	}

	/**
	 * Marks it read for that account.
	 *
	 * The announcement is read first so that an id that names nothing is a 404
	 * rather than a dismissal row pointing at nothing — and an announcement
	 * outside its window can still be dismissed, because a client that was
	 * showing it when it ran out must be able to put it away.
	 *
	 * @throws ItemNotFoundException
	 */
	public function dismiss(int $id, string $actorId): void {
		$announcement = $this->announcementsRequest->getById($id);
		$this->announcementsRequest->dismiss($announcement->getId(), $actorId);
	}

	/**
	 * Every announcement, newest first, as the administration page shows one:
	 * the text as it was typed, its window, and whether it is being served at
	 * this moment.
	 *
	 * Not the client entity — an admin is looking at what they wrote, not at
	 * the HTML a client renders, and `read` would be the admin's own.
	 *
	 * @return array<int, array<string, string|bool|null>>
	 */
	public function adminList(?int $now = null): array {
		$now ??= time();

		$rows = [];
		foreach ($this->announcementsRequest->getAll() as $announcement) {
			$rows[] = [
				'id' => (string)$announcement->getId(),
				'text' => $announcement->getText(),
				'starts_at' => Announcement::datetime($announcement->getStartsAt()),
				'ends_at' => Announcement::datetime($announcement->getEndsAt()),
				'all_day' => $announcement->hasRange() && $announcement->isAllDay(),
				'published_at' => Announcement::datetime($announcement->getPublishedAt()),
				'active' => $announcement->isActiveAt($now),
			];
		}

		return $rows;
	}

	/**
	 * Posts an announcement.
	 *
	 * @param string $startsAt when it starts applying, as a date or datetime
	 *                         the server can read; '' for no bound
	 * @param string $endsAt the same, for when it stops
	 *
	 * @throws InvalidResourceException nothing was typed, it is longer than
	 *                                  the column takes, a date cannot be
	 *                                  read, only one bound was given, or the
	 *                                  window ends before it starts
	 */
	public function create(string $text, string $startsAt = '', string $endsAt = '', bool $allDay = false): Announcement {
		$text = trim($text);
		if ($text === '') {
			throw new InvalidResourceException('Text cannot be blank');
		}

		if (mb_strlen($text, 'UTF-8') > Announcement::MAX_TEXT) {
			throw new InvalidResourceException(
				'Text is longer than ' . Announcement::MAX_TEXT . ' characters'
			);
		}

		$starts = $this->timestamp($startsAt, 'starts_at');
		$ends = $this->timestamp($endsAt, 'ends_at');

		// Mastodon's own rule: a range is both of its bounds. One bound alone
		// describes no window, and the pair is what `all_day` is about
		if (($starts === 0) !== ($ends === 0)) {
			throw new InvalidResourceException('starts_at and ends_at are given together or not at all');
		}

		if ($allDay && $starts > 0) {
			// whole days rather than the minute the admin happened to pick:
			// the window opens at the start of its first day and closes at the
			// start of the day after its last, which is the moment the read
			// stops matching
			$starts = (int)strtotime('midnight', $starts);
			$ends = (int)strtotime('midnight +1 day', $ends);
		}

		if ($ends > 0 && $ends <= $starts) {
			throw new InvalidResourceException('ends_at is not after starts_at');
		}

		$announcement = new Announcement();
		$announcement->setText($text)
			->setStartsAt($starts)
			->setEndsAt($ends)
			->setAllDay($allDay && $starts > 0);

		$this->announcementsRequest->save($announcement);

		return $announcement;
	}

	/**
	 * Removes an announcement and every dismissal of it.
	 *
	 * @throws ItemNotFoundException there is no such announcement
	 */
	public function delete(int $id): void {
		$this->announcementsRequest->delete($id);
	}

	/** When an announcement takes effect: its start, or its publication. */
	private static function effectiveStart(Announcement $announcement): int {
		return ($announcement->getStartsAt() > 0)
			? $announcement->getStartsAt()
			: $announcement->getPublishedAt();
	}

	/**
	 * @throws InvalidResourceException the field is not a date this server can
	 *                                  read — refused rather than stored as
	 *                                  "no bound", which would publish to
	 *                                  everybody an announcement the admin
	 *                                  scheduled
	 */
	private function timestamp(string $date, string $field): int {
		$date = trim($date);
		if ($date === '') {
			return 0;
		}

		$timestamp = strtotime($date);
		if ($timestamp === false) {
			throw new InvalidResourceException($field . ' is not a date');
		}

		return $timestamp;
	}
}
