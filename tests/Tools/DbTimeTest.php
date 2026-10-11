<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools;

use OCA\Social\Model\AccessBlock;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Tools\DbTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A stored DATETIME is UTC wall-clock time. Each test runs with PHP in a zone
 * fourteen hours off UTC, so a reader that falls back to the local zone is
 * half a day wrong rather than silently right.
 */
class DbTimeTest extends TestCase {
	private string $zone;

	protected function setUp(): void {
		parent::setUp();
		$this->zone = date_default_timezone_get();
		date_default_timezone_set('Pacific/Kiritimati');
	}

	protected function tearDown(): void {
		date_default_timezone_set($this->zone);
		parent::tearDown();
	}

	#[DataProvider('storedValues')]
	public function testAStoredDatetimeIsReadAsUtc(mixed $stored, int $expected): void {
		$this->assertSame($expected, DbTime::toTimestamp($stored));
	}

	/** @return array<string, array{mixed, int}> */
	public static function storedValues(): array {
		return [
			'the shape every database returns' => ['2026-10-09 12:00:00', 1791547200],
			'with fractions of a second' => ['2026-10-09 12:00:00.000000', 1791547200],
			'with its own offset, which is kept' => ['2026-10-09 14:00:00+02:00', 1791547200],
			'the epoch' => ['1970-01-01 00:00:00', 0],
			'a NULL column' => [null, 0],
			'an empty column' => ['', 0],
			'whitespace' => ['   ', 0],
			'not a date' => ['not a date', 0],
		];
	}

	/** What one process writes from a timestamp, another reads back as the same moment. */
	public function testWhatIsWrittenFromATimestampReadsBackAsThatTimestamp(): void {
		$written = (new \DateTime('@1791547200'))->format('Y-m-d H:i:s');

		$this->assertSame('2026-10-09 12:00:00', $written);
		$this->assertSame(1791547200, DbTime::toTimestamp($written));
	}

	public function testAnAccessBlockReadsItsTimesAsUtc(): void {
		$block = AccessBlock::fromRow([
			'type' => AccessBlock::TYPE_IP,
			'value' => '192.0.2.0/24',
			'expires' => '1970-01-01 00:33:20',
			'creation' => '2026-10-09 12:00:00',
		]);

		$this->assertSame(2000, $block->getExpires());
		$this->assertSame(1791547200, $block->getCreation());
		$this->assertSame('1970-01-01T00:33:20.000Z', $block->jsonSerialize()['expires_at']);
	}

	public function testAnAccessBlockWithNoExpiryNeverLifts(): void {
		$block = AccessBlock::fromRow(['expires' => null, 'creation' => '2026-10-09 12:00:00']);

		$this->assertSame(0, $block->getExpires());
		$this->assertTrue($block->isLiveAt(PHP_INT_MAX));
	}

	public function testAPostsPublishedTimeIsReadAsUtc(): void {
		$note = new Note();
		$note->importFromDatabase([
			'id' => 'https://cloud.example/apps/social/@alice/1',
			'type' => 'Note',
			'published_time' => '2026-10-09 12:00:00',
		]);

		$this->assertSame(1791547200, $note->getPublishedTime());
	}
}
