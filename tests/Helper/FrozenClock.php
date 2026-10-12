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
 * A clock that stands still until a test moves it.
 *
 * The lifecycle tests walk something through its expiry: they set the clock a
 * second before the moment, check that the thing still holds, move it past
 * and check that it no longer does. Every reading is taken from the one
 * instant, in UTC, the zone Nextcloud runs the app in.
 */
final class FrozenClock implements ITimeFactory {
	private DateTimeZone $zone;

	public function __construct(
		private int $now,
	) {
		$this->zone = new DateTimeZone('UTC');
	}

	/** The clock at a moment written as an ISO 8601 date, for the calendar edge cases. */
	public static function at(string $moment): self {
		return new self((new DateTimeImmutable($moment, new DateTimeZone('UTC')))->getTimestamp());
	}

	public function set(int $now): self {
		$this->now = $now;

		return $this;
	}

	public function advance(int $seconds): self {
		$this->now += $seconds;

		return $this;
	}

	#[\Override]
	public function getTime(): int {
		return $this->now;
	}

	#[\Override]
	public function getDateTime(string $time = 'now', ?DateTimeZone $timezone = null): DateTime {
		$base = (new DateTime('@' . $this->now))->setTimezone($timezone ?? $this->zone);

		return ($time === 'now') ? $base : $base->modify($time);
	}

	#[\Override]
	public function withTimeZone(DateTimeZone $timezone): static {
		$clock = clone $this;
		$clock->zone = $timezone;

		return $clock;
	}

	#[\Override]
	public function getTimeZone(?string $timezone = null): DateTimeZone {
		return new DateTimeZone($timezone ?? 'UTC');
	}

	#[\Override]
	public function now(): DateTimeImmutable {
		return (new DateTimeImmutable('@' . $this->now))->setTimezone($this->zone);
	}
}
