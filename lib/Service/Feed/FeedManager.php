<?php

declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Service\Feed;

use OCA\Social\Entity\Account;
use OCA\Social\Entity\Status;

/**
 * This service handle storing and retrieving the feeds from Redis
 */
class FeedManager {
	/**
	 * Number of items in the feed since last reblog of status
	 * before the new reblog will be inserted. Must be <= MAX_ITEMS
	 * or the tracking sets will grow forever
	 */
	const REBLOG_FALLOFF = 40;

	const HOME_FEED = "home";
	const MAX_ITEM = 400;

	private IFeedProvider $feedProvider;

	public function __construct(IFeedProvider $feedProvider) {
		$this->feedProvider = $feedProvider;
	}

	public function addToHome(string $accountId, Status $status): bool {
		return $this->addToFeed(self::HOME_FEED, $accountId, $status);
	}

	public function removeFromHome(string $accountId, Status $status): bool {
		return $this->removeFromFeed(self::HOME_FEED, $accountId, $status);
	}

	public function addToFeed(string $timelineType, string $accountId, Status $status, bool $aggregateReblog = true): bool {
		return $this->feedProvider->addToFeed($timelineType, $accountId, $status, $aggregateReblog);
	}

	public function removeFromFeed(string $timelineType, string $accountId, Status $status, bool $aggregateReblog = true): bool {
		return $this->feedProvider->removeFromFeed($timelineType, $accountId, $status, $aggregateReblog);
	}

	public function mergeIntoHome(Account $fromAccount, Account $toAccount): void {
		$this->feedProvider->mergeIntoHome($fromAccount, $toAccount);
	}
}
