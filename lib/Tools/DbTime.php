<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Stored DATETIME columns, which hold UTC wall-clock time: Nextcloud pins
 * PHP to UTC before anything writes one.
 */
final class DbTime {
	/**
	 * A stored DATETIME as unix time, 0 when empty or unreadable.
	 *
	 * Read as UTC whatever zone PHP runs in; a value carrying its own offset
	 * keeps it.
	 */
	public static function toTimestamp(mixed $stored): int {
		if (!is_scalar($stored) || trim((string)$stored) === '') {
			return 0;
		}

		try {
			return (new DateTimeImmutable((string)$stored, new DateTimeZone('UTC')))->getTimestamp();
		} catch (Exception) {
			return 0;
		}
	}
}
