<?php

declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Service;

use OCA\Social\Entity\Status;

interface IFeedProvider {
	public function addToFeed(string $timelineType, string $accountId, Status $status, bool $aggregateReblog = true): bool;

	public function removeFromFeed(string $timelineType, string $accountId, Status $status, bool $aggregateReblog = true): bool;
}
