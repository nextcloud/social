<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Model\ActivityPub\Actor\Person;

interface IRecommendationSignalEvents {
	/** @param array<array-key, mixed> $events @return array{accepted: int, duplicates: int, discarded: int, profile_version: int} */
	public function recordEvents(Person $viewer, array $events): array;
}
