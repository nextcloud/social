<?php

declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Service\Feed;

use OCA\Social\Entity\Account;
use OCA\Social\Entity\Status;

/**
 * Interface abstracting the feed. Currently, there is only one implementation
 * relying on Redis.
 */
interface IFeedProvider {
	/**
	 * Add a status from a feed
	 */
	public function addToFeed(string $timelineType, string $accountId, Status $status, bool $aggregateReblog = true): bool;

	/**
	 * Remove a status from a feed
	 */
	public function removeFromFeed(string $timelineType, string $accountId, Status $status, bool $aggregateReblog = true): bool;

	/**
	 * Fill a home feed with an account's status
	 */
	public function mergeIntoHome(Account $fromAccount, Account $toAccount): void;
}
