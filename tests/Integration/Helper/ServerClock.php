<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Helper;

use ArrayObject;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OC\AppFramework\Utility\TimeFactory;
use OCA\Social\Tests\Helper\FrozenClock;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The clock `FreezesTheClock` puts on the server container: the test's
 * `FrozenClock` until it is thawed, the real one after.
 *
 * The server builds the app's services lazily, so a service the boot
 * container had handed out earlier takes its clock when it is first used.
 * Used while a test had the clock frozen, it kept that test's clock for the
 * rest of the run. Thawing this one puts every service that took it back on
 * the real time, whenever it was built. Copies made by `withTimeZone()`
 * share the switch.
 */
final class ServerClock implements ITimeFactory {
	/** @var ArrayObject<string, FrozenClock|null> the frozen clock, under `frozen`, while there is one */
	private ArrayObject $state;
	private ?DateTimeZone $zone = null;

	public function __construct(FrozenClock $frozen) {
		$this->state = new ArrayObject(['frozen' => $frozen]);
	}

	public function stopAt(FrozenClock $frozen): void {
		$this->state['frozen'] = $frozen;
	}

	public function thaw(): void {
		$this->state['frozen'] = null;
	}

	private function clock(): ITimeFactory {
		$clock = $this->state['frozen'] ?? new TimeFactory();

		return ($this->zone === null) ? $clock : $clock->withTimeZone($this->zone);
	}

	#[\Override]
	public function getTime(): int {
		return $this->clock()->getTime();
	}

	#[\Override]
	public function getDateTime(string $time = 'now', ?DateTimeZone $timezone = null): DateTime {
		return $this->clock()->getDateTime($time, $timezone);
	}

	#[\Override]
	public function withTimeZone(DateTimeZone $timezone): static {
		$clock = clone $this;
		$clock->zone = $timezone;

		return $clock;
	}

	#[\Override]
	public function getTimeZone(?string $timezone = null): DateTimeZone {
		return $this->clock()->getTimeZone($timezone);
	}

	#[\Override]
	public function now(): DateTimeImmutable {
		return $this->clock()->now();
	}
}
