<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The wall clock, for the unit suite's container: what the server's own
 * `TimeFactory` answers for code that asks `Server::get(ITimeFactory::class)`.
 */
final class SystemClock implements ITimeFactory {
	#[\Override]
	public function getTime(): int {
		return time();
	}

	#[\Override]
	public function getDateTime(string $time = 'now', ?DateTimeZone $timezone = null): DateTime {
		return new DateTime($time, $timezone ?? new DateTimeZone('UTC'));
	}

	#[\Override]
	public function withTimeZone(DateTimeZone $timezone): static {
		return $this;
	}

	#[\Override]
	public function getTimeZone(?string $timezone = null): DateTimeZone {
		return new DateTimeZone($timezone ?? 'UTC');
	}

	#[\Override]
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}
}
