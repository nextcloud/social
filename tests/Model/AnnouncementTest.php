<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use OCA\Social\Model\Client\Announcement;
use PHPUnit\Framework\TestCase;

/**
 * The Announcement entity: the shape a client is handed, and the window
 * predicate every reader of one compares with.
 *
 * A client that cannot decode this entity shows nothing and says nothing, so
 * the keys are asserted as a set and not one by one.
 */
class AnnouncementTest extends TestCase {
	private function announcement(string $text = 'Maintenance on Sunday'): Announcement {
		return (new Announcement())
			->setId(7)
			->setText($text)
			->setPublishedAt(1_700_000_000)
			->setUpdatedAt(1_700_000_000);
	}

	public function testTheEntityCarriesEveryKeyMastodonDocumentsAsNonOptional(): void {
		$this->assertSame(
			[
				'id', 'content', 'starts_at', 'ends_at', 'all_day', 'published_at',
				'updated_at', 'read', 'mentions', 'statuses', 'tags', 'emojis', 'reactions',
			],
			array_keys($this->announcement()->jsonSerialize())
		);
	}

	public function testTheFiveThingsAnAnnouncementNeverHasAreSentAsEmptyArrays(): void {
		// a client that declares them non-optional cannot decode the entity
		// without them, whatever they hold
		$entity = $this->announcement()->jsonSerialize();

		foreach (['mentions', 'statuses', 'tags', 'emojis', 'reactions'] as $key) {
			$this->assertSame([], $entity[$key], $key . ' is missing its empty array');
		}
	}

	public function testTheIdIsAStringAsEveryIdAClientIsHandedIs(): void {
		$this->assertSame('7', $this->announcement()->jsonSerialize()['id']);
	}

	public function testTheDatesAreTheFormatEveryOtherEntityHereUses(): void {
		$entity = $this->announcement()->jsonSerialize();

		$this->assertSame('2023-11-14T22:13:20.000Z', $entity['published_at']);
		$this->assertSame('2023-11-14T22:13:20.000Z', $entity['updated_at']);
	}

	public function testAnAnnouncementWithNoWindowSendsNullBoundsRatherThanTheEpoch(): void {
		$entity = $this->announcement()->jsonSerialize();

		$this->assertNull($entity['starts_at']);
		$this->assertNull($entity['ends_at']);
	}

	public function testWhatTheAdminTypedIsEscapedOnItsWayIntoTheHtmlContent(): void {
		// content is HTML and a client renders it as such: a notice about an
		// <IfModule> block must not become a tag in everybody's timeline
		$entity = $this->announcement('Check your <IfModule> & restart')->jsonSerialize();

		$this->assertSame(
			'<p>Check your &lt;IfModule&gt; &amp; restart</p>',
			$entity['content']
		);
	}

	public function testABlankLineStartsAParagraphAndASingleBreakStaysABreak(): void {
		$entity = $this->announcement("first\nsecond\n\nthird")->jsonSerialize();

		$this->assertSame('<p>first<br />second</p><p>third</p>', $entity['content']);
	}

	public function testTheTextIsKeptAsItWasTypedSoTheAdminSeesTheirOwnWords(): void {
		$announcement = $this->announcement('Check your <IfModule>');

		$this->assertSame('Check your <IfModule>', $announcement->getText());
	}

	public function testAllDayIsFalseWithoutAWindowToBeWholeDaysOf(): void {
		// Mastodon: "false if no start/end times exist"
		$entity = $this->announcement()->setAllDay(true)->jsonSerialize();

		$this->assertFalse($entity['all_day']);
	}

	public function testAllDayStandsWhenThereIsAWindow(): void {
		$entity = $this->announcement()
			->setAllDay(true)
			->setStartsAt(1_700_000_000)
			->setEndsAt(1_700_100_000)
			->jsonSerialize();

		$this->assertTrue($entity['all_day']);
	}

	public function testAnAnnouncementWithNoWindowAppliesForGood(): void {
		$this->assertTrue($this->announcement()->isActiveAt(1));
		$this->assertTrue($this->announcement()->isActiveAt(2_000_000_000));
	}

	public function testAnAnnouncementIsNotShownBeforeItStartsOrAfterItEnds(): void {
		$announcement = $this->announcement()
			->setStartsAt(1_000)
			->setEndsAt(2_000);

		$this->assertFalse($announcement->isActiveAt(999));
		$this->assertTrue($announcement->isActiveAt(1_000));
		$this->assertTrue($announcement->isActiveAt(1_999));
		$this->assertFalse($announcement->isActiveAt(2_000));
		$this->assertFalse($announcement->isActiveAt(3_000));
	}

	public function testAWindowIsBothOfItsBounds(): void {
		$this->assertFalse($this->announcement()->setStartsAt(1_000)->hasRange());
		$this->assertTrue($this->announcement()->setStartsAt(1_000)->setEndsAt(2_000)->hasRange());
	}

	public function testARowBecomesTheEntityItWasStoredFrom(): void {
		$announcement = (new Announcement())->importFromDatabase([
			'id' => '7',
			'content' => 'Maintenance on Sunday',
			'starts_at' => '2023-11-14 22:13:20',
			'ends_at' => '2023-11-15 22:13:20',
			'all_day' => '1',
			'creation' => '2023-11-13 22:13:20',
			'last_update' => '2023-11-13 22:13:20',
		]);

		$this->assertSame(7, $announcement->getId());
		$this->assertSame('Maintenance on Sunday', $announcement->getText());
		$this->assertSame(strtotime('2023-11-14 22:13:20'), $announcement->getStartsAt());
		$this->assertSame(strtotime('2023-11-15 22:13:20'), $announcement->getEndsAt());
		$this->assertTrue($announcement->isAllDay());
		$this->assertSame(strtotime('2023-11-13 22:13:20'), $announcement->getPublishedAt());
	}

	public function testAnAnnouncementNeverEditedWasLastUpdatedWhenItWasWritten(): void {
		// Mastodon documents updated_at as non-nullable, so it cannot be empty
		// just because nothing has happened to the row
		$announcement = (new Announcement())->importFromDatabase([
			'id' => '7',
			'content' => 'Maintenance on Sunday',
			'creation' => '2023-11-13 22:13:20',
			'last_update' => null,
		]);

		$this->assertSame(
			$announcement->jsonSerialize()['published_at'],
			$announcement->jsonSerialize()['updated_at']
		);
		$this->assertNotSame('', $announcement->jsonSerialize()['updated_at']);
	}
}
