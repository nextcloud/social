<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Nid;

/**
 * Mastodon 4.3's grouped notifications: "eight people favourited your post"
 * instead of eight rows saying the same thing.
 *
 * The grouping is the whole feature. A notification list where one popular
 * post produces forty rows is a list in which everything else is unreachable,
 * and a client cannot fix that for itself — it would have to fetch every page
 * to know how many of the forty there were.
 *
 * What may be grouped is decided by what the reader would recognise as one
 * event: several people doing the same thing to the same post (favourites,
 * boosts, reactions), or several people following the reader. A mention is
 * never grouped — two people writing to you are two things to read, not one
 * thing that happened twice — and neither is anything about a poll, an edit or
 * a moderation decision.
 *
 * The group key is what a client uses to fetch, dismiss or refresh one group,
 * so it has to name the same group tomorrow. It is built from what the group
 * *is* — the type and the post, or the type alone — never from the ids of the
 * notifications in it, which change as more arrive. An ungrouped notification
 * gets `ungrouped-{id}`, which is Mastodon's convention and lets a client
 * treat both kinds the same way.
 */
class NotificationGroupService {
	/**
	 * The notification types that group. Everything else stands alone.
	 *
	 * Favourites and boosts, because several people doing the same thing to
	 * the same post is one event to the reader. Not mentions: two people
	 * writing to you are two things to read.
	 */
	public const GROUPED_TYPES = ['favourite', 'reblog'];

	/** Types that group with no status behind them — one group of their own. */
	public const GROUPED_BY_TYPE = ['follow', 'follow_request'];

	/** How many accounts a group carries as a sample. Mastodon's own ceiling. */
	public const SAMPLE_ACCOUNTS = 8;

	/**
	 * Groups a page of notifications, newest group first.
	 *
	 * The page is grouped, not the account's whole history: a group says how
	 * many of it are *on this page*, which is what `page_min_id` and
	 * `page_max_id` are for. A client that wants the rest pages on.
	 *
	 * @param Stream[] $notifications newest first, as the timeline serves them
	 * @param string[] $groupedTypes the types the client asked to have grouped;
	 *                               an empty list means the default set
	 *
	 * @return array{notification_groups: array<int, array<string, mixed>>, accounts: array<int, mixed>, statuses: array<int, mixed>}
	 */
	public function group(array $notifications, array $groupedTypes = []): array {
		$grouping = ($groupedTypes === [])
			? self::GROUPED_TYPES
			: array_values(array_intersect($groupedTypes, self::GROUPED_TYPES));

		/** @var array<string, array<string, mixed>> $groups */
		$groups = [];
		$accounts = [];
		$statuses = [];

		foreach ($notifications as $notification) {
			$type = Stream::notificationTypeOfSubType($notification->getSubType());
			if ($type === '') {
				continue;
			}

			$status = $notification->getObject();
			$statusId = ($status instanceof Stream) ? (string)$status->getNid() : '';
			$key = $this->keyFor($notification, $type, $statusId, $grouping);
			$id = (string)$notification->getNid();
			$at = gmdate('Y-m-d\TH:i:s', $notification->getPublishedTime()) . '.000Z';

			if (!isset($groups[$key])) {
				$groups[$key] = [
					'group_key' => $key,
					'notifications_count' => 0,
					'type' => $type,
					// the page is newest first, so the first notification of a
					// group is its most recent one
					'most_recent_notification_id' => $id,
					'page_min_id' => $id,
					'page_max_id' => $id,
					'latest_page_notification_at' => $at,
					'sample_account_ids' => [],
				];

				if ($statusId !== '') {
					$groups[$key]['status_id'] = $statusId;
					$status->setExportFormat(ACore::FORMAT_LOCAL);
					$statuses[$statusId] = $status;
				}
			}

			$groups[$key]['notifications_count']++;
			// ids here are snowflakes and are compared as numbers, not as
			// strings ('9' is not larger than '10'), and not as PHP ints either,
			// which a wide one does not fit
			if (Nid::compare($id, (string)$groups[$key]['page_min_id']) < 0) {
				$groups[$key]['page_min_id'] = $id;
			}
			if (Nid::compare($id, (string)$groups[$key]['page_max_id']) > 0) {
				$groups[$key]['page_max_id'] = $id;
			}

			if ($notification->hasActor()) {
				$actor = $notification->getActor();
				$actorId = (string)$actor->getNid();
				if (count($groups[$key]['sample_account_ids']) < self::SAMPLE_ACCOUNTS
					&& !in_array($actorId, $groups[$key]['sample_account_ids'], true)) {
					$groups[$key]['sample_account_ids'][] = $actorId;
				}

				$actor->setExportFormat(ACore::FORMAT_LOCAL);
				$accounts[$actorId] = $actor;
			}
		}

		return [
			'notification_groups' => array_values($groups),
			// the accounts and statuses the groups refer to, each once: that is
			// the point of the v2 shape, and why a page of forty favourites of
			// one post carries one status rather than forty copies of it
			'accounts' => array_values($accounts),
			'statuses' => array_values($statuses),
		];
	}

	/**
	 * The notifications of one group, out of a page.
	 *
	 * @param Stream[] $notifications
	 * @param string[] $groupedTypes
	 *
	 * @return Stream[]
	 */
	public function membersOf(array $notifications, string $groupKey, array $groupedTypes = []): array {
		$grouping = ($groupedTypes === []) ? self::GROUPED_TYPES : $groupedTypes;

		$members = [];
		foreach ($notifications as $notification) {
			$type = Stream::notificationTypeOfSubType($notification->getSubType());
			if ($type === '') {
				continue;
			}

			$status = $notification->getObject();
			$statusId = ($status instanceof Stream) ? (string)$status->getNid() : '';
			if ($this->keyFor($notification, $type, $statusId, $grouping) === $groupKey) {
				$members[] = $notification;
			}
		}

		return $members;
	}

	/**
	 * @param string[] $grouping
	 */
	private function keyFor(Stream $notification, string $type, string $statusId, array $grouping): string {
		if (in_array($type, $grouping, true) && $statusId !== '') {
			return $type . '-' . $statusId;
		}

		if (in_array($type, self::GROUPED_BY_TYPE, true)) {
			return $type . '-all';
		}

		return 'ungrouped-' . $notification->getNid();
	}
}
