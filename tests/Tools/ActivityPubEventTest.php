<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Details;
use OCA\Social\Tools\ActivityPubEvent;
use PHPUnit\Framework\TestCase;

class ActivityPubEventTest extends TestCase {
	public function testDateAndPlaceMetadataIsValidatedAndMadePlainText(): void {
		$metadata = ActivityPubEvent::metadata([
			'name' => "<b>Meetup</b>\nGroup",
			'startTime' => '2026-04-05T18:30:00+02:00',
			'endTime' => '2026-04-05T17:30:00+02:00',
			'location' => [
				'name' => '',
				'address' => [
					'streetAddress' => 'Main Street 12',
					'addressLocality' => 'Berlin',
					'addressCountry' => 'DE',
				],
			],
		]);

		self::assertSame('Meetup Group', $metadata['name']);
		self::assertSame('2026-04-05T16:30:00Z', $metadata['start_time']);
		self::assertNull($metadata['end_time'], 'an end before the start is discarded');
		self::assertSame('Main Street 12, Berlin, DE', $metadata['location']);
	}

	public function testCalendarEscapesTextUsesUtcAndFoldsLongLines(): void {
		$event = (new Note())
			->setId('https://mobilizon.example/events/9f')
			->setUrl('https://mobilizon.example/events/9f')
			->setContent("<p>Talk; about PHP, security\\\nToday</p>")
			->setSubType('Event');
		$event->setDetailArray(Details::EVENT, [
			'name' => 'A very long meetup title about ActivityPub events and interoperable calendar publishing across different Fediverse servers',
			'start_time' => '2026-04-05T16:30:00Z',
			'end_time' => '2026-04-05T19:00:00Z',
			'location' => 'Community hall, Berlin',
		]);

		$ics = ActivityPubEvent::calendar($event, 1_800_000_000);

		self::assertIsString($ics);
		self::assertStringContainsString('DTSTART:20260405T163000Z', $ics);
		self::assertStringContainsString('DTEND:20260405T190000Z', $ics);
		self::assertStringContainsString('LOCATION:Community hall\\, Berlin', $ics);
		self::assertStringContainsString('SUMMARY:A very long meetup', $ics);
		self::assertStringContainsString("\r\n ", $ics, 'long content lines are folded');
		self::assertStringContainsString('DESCRIPTION:Talk\\; about PHP\\, security\\\\ Today', $ics);
		self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
		foreach (explode("\r\n", trim($ics)) as $line) {
			self::assertLessThanOrEqual(75, strlen($line), 'iCalendar lines are at most 75 octets');
		}
	}

	public function testCalendarIsUnavailableForNotesAndUndatedEvents(): void {
		$note = (new Note())->setId('https://example.org/notes/1');
		self::assertNull(ActivityPubEvent::calendar($note, 1_800_000_000));

		$event = (new Note())->setId('https://example.org/events/1')->setSubType('Event');
		self::assertNull(ActivityPubEvent::calendar($event, 1_800_000_000));
	}
}
