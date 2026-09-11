<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use DateTime;
use OCA\Social\Db\ScheduledStatusesRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * What the storage of a scheduled post decides without a database.
 *
 * The statements themselves — the owner predicate on every read, and the
 * delete that claims a due row for exactly one worker — need a real database
 * and belong to the integration suite: `SocialQueryBuilder` extends a
 * server-private class that cannot be loaded standalone.
 */
class ScheduledStatusesRequestTest extends TestCase {
	private string $timezone;

	protected function setUp(): void {
		$this->timezone = date_default_timezone_get();
	}

	protected function tearDown(): void {
		date_default_timezone_set($this->timezone);
	}

	private function dateTime(int $timestamp): DateTime {
		$reflection = new ReflectionClass(ScheduledStatusesRequest::class);

		return $reflection->getMethod('dateTime')
			->invoke($reflection->newInstanceWithoutConstructor(), $timestamp);
	}

	/**
	 * DBAL formats a DateTime in whatever zone the object carries, and every
	 * other date in this schema is written from `new DateTime('now')` — the
	 * server's zone. A time built from a timestamp is UTC unless it is moved,
	 * so on an instance that is not on UTC the row would be stored hours away
	 * from the `now` the job compares it against, and a post would be published
	 * hours before its author meant it to be.
	 */
	public function testATimeIsWrittenInTheZoneEveryOtherDateInThisSchemaIsWrittenIn(): void {
		date_default_timezone_set('Asia/Tokyo');

		$written = $this->dateTime(1760000000);

		$this->assertSame('Asia/Tokyo', $written->getTimezone()->getName());
		$this->assertSame(
			(new DateTime('now'))->getTimezone()->getName(),
			$written->getTimezone()->getName()
		);
	}

	public function testMovingTheZoneDoesNotMoveTheInstant(): void {
		date_default_timezone_set('Asia/Tokyo');
		$tokyo = $this->dateTime(1760000000);

		date_default_timezone_set('America/Sao_Paulo');
		$saoPaulo = $this->dateTime(1760000000);

		$this->assertSame(1760000000, $tokyo->getTimestamp());
		$this->assertSame(1760000000, $saoPaulo->getTimestamp());
		// …and the wall clock they are written as differs, which is the point:
		// each instance stores the local time its own `now` is compared with
		$this->assertNotSame($tokyo->format('Y-m-d H:i:s'), $saoPaulo->format('Y-m-d H:i:s'));
	}
}
