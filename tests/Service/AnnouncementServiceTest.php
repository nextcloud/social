<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\AnnouncementsRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\Announcement;
use OCA\Social\Service\AnnouncementService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What an announcement is worth to the two surfaces that read it: the client,
 * which gets the ones that apply now with its own read state, and the
 * administration page, which gets all of them with none.
 *
 * The storage is a table in memory that answers the way the real one does —
 * the window in `getActive()`, the account in `dismissedBy()` — because the
 * standalone suite has no database. The SQL that has to make the same two
 * decisions is exercised by the integration suite.
 */
class AnnouncementServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/users/alice';
	private const BOB = 'https://cloud.example/users/bob';

	/** @var array<int, Announcement> stored announcements, by id */
	private array $stored = [];
	/** @var array<string, int[]> actor id => the announcement ids it dismissed */
	private array $dismissals = [];
	private int $nextId = 1;
	private AnnouncementService $service;

	protected function setUp(): void {
		/** @var AnnouncementsRequest&MockObject $request */
		$request = $this->getMockBuilder(AnnouncementsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['save', 'getAll', 'getActive', 'getById', 'delete', 'dismiss', 'dismissedBy'])
			->getMock();

		$request->method('save')->willReturnCallback(function (Announcement $announcement): int {
			$id = $this->nextId++;
			$announcement->setId($id)->setPublishedAt($announcement->getPublishedAt() ?: 1_000);
			$this->stored[$id] = $announcement;

			return $id;
		});

		$request->method('getAll')->willReturnCallback(
			fn (): array => array_reverse(array_values($this->stored))
		);

		// the window is the read's, so the double applies it too rather than
		// handing back rows the production query would never have returned
		$request->method('getActive')->willReturnCallback(fn (?int $now = null): array => array_values(
			array_filter(
				$this->stored,
				static fn (Announcement $announcement): bool => $announcement->isActiveAt($now)
			)
		));

		$request->method('getById')->willReturnCallback(function (int $id): Announcement {
			if (!isset($this->stored[$id])) {
				throw new ItemNotFoundException('announcement not found');
			}

			return $this->stored[$id];
		});

		$request->method('delete')->willReturnCallback(function (int $id): void {
			if (!isset($this->stored[$id])) {
				throw new ItemNotFoundException('announcement not found');
			}

			unset($this->stored[$id]);
			foreach ($this->dismissals as $actorId => $ids) {
				$this->dismissals[$actorId] = array_values(array_diff($ids, [$id]));
			}
		});

		$request->method('dismiss')->willReturnCallback(function (int $id, string $actorId): void {
			$this->dismissals[$actorId][] = $id;
		});

		$request->method('dismissedBy')->willReturnCallback(
			fn (string $actorId, array $ids): array
				=> array_values(array_intersect($this->dismissals[$actorId] ?? [], $ids))
		);

		$this->service = new AnnouncementService($request);
	}

	/** @return int[] the ids of the announcements that account is served */
	private function servedTo(string $actorId, ?int $now = null): array {
		return array_map(
			static fn (Announcement $announcement): int => $announcement->getId(),
			$this->service->active($actorId, $now)
		);
	}

	private function store(Announcement $announcement): Announcement {
		$announcement->setId($this->nextId++);
		$this->stored[$announcement->getId()] = $announcement;

		return $announcement;
	}

	private function announcement(string $text, int $startsAt = 0, int $endsAt = 0, int $publishedAt = 1_000): Announcement {
		return $this->store(
			(new Announcement())
				->setText($text)
				->setStartsAt($startsAt)
				->setEndsAt($endsAt)
				->setPublishedAt($publishedAt)
		);
	}

	public function testAnAnnouncementWithNoWindowIsServedToEverybody(): void {
		$announcement = $this->announcement('Maintenance on Sunday');

		$this->assertSame([$announcement->getId()], $this->servedTo(self::ALICE, 5_000));
	}

	public function testAnAnnouncementOutsideItsWindowIsNotServed(): void {
		$this->announcement('Over', 1_000, 2_000);
		$this->announcement('Not yet', 8_000, 9_000);
		$live = $this->announcement('Now', 4_000, 6_000);

		// nothing ran to make that happen: the two that do not apply are still
		// stored, and the admin can still see them
		$this->assertSame([$live->getId()], $this->servedTo(self::ALICE, 5_000));
		$this->assertCount(3, $this->service->adminList(5_000));
	}

	public function testAnAnnouncementIsServedFromItsStartUpToItsEnd(): void {
		$announcement = $this->announcement('Now', 4_000, 6_000);

		$this->assertSame([], $this->servedTo(self::ALICE, 3_999));
		$this->assertSame([$announcement->getId()], $this->servedTo(self::ALICE, 4_000));
		$this->assertSame([$announcement->getId()], $this->servedTo(self::ALICE, 5_999));
		$this->assertSame([], $this->servedTo(self::ALICE, 6_000));
	}

	public function testDismissingMarksItReadForThatAccountAndForNobodyElse(): void {
		$announcement = $this->announcement('Maintenance on Sunday');
		$this->service->dismiss($announcement->getId(), self::ALICE);

		$this->assertTrue($this->service->active(self::ALICE, 5_000)[0]->isRead());
		$this->assertFalse($this->service->active(self::BOB, 5_000)[0]->isRead());
	}

	public function testADismissedAnnouncementIsStillServedWithItsReadFlagSet(): void {
		// Mastodon keeps serving it and flips `read`: a client shows the
		// notice without its unread mark rather than having it vanish
		$announcement = $this->announcement('Maintenance on Sunday');
		$this->service->dismiss($announcement->getId(), self::ALICE);

		$this->assertSame([$announcement->getId()], $this->servedTo(self::ALICE, 5_000));
	}

	public function testDismissingOneAnnouncementLeavesTheOthersUnread(): void {
		$first = $this->announcement('First');
		$this->announcement('Second');
		$this->service->dismiss($first->getId(), self::ALICE);

		$read = [];
		foreach ($this->service->active(self::ALICE, 5_000) as $announcement) {
			$read[$announcement->getText()] = $announcement->isRead();
		}

		$this->assertSame(['First' => true, 'Second' => false], $read);
	}

	public function testDismissingTwiceIsNotAnError(): void {
		$announcement = $this->announcement('Maintenance on Sunday');

		$this->service->dismiss($announcement->getId(), self::ALICE);
		$this->service->dismiss($announcement->getId(), self::ALICE);

		$this->assertTrue($this->service->active(self::ALICE, 5_000)[0]->isRead());
	}

	public function testAnAnnouncementThatRanOutCanStillBeDismissed(): void {
		// a client showing it when the window closed has to be able to put it
		// away; the dismissal is read state and not a second window
		$announcement = $this->announcement('Over', 1_000, 2_000);

		$this->service->dismiss($announcement->getId(), self::ALICE);

		$this->assertSame([], $this->servedTo(self::ALICE, 5_000));
	}

	public function testDismissingSomethingThatIsNotThereIsNotFound(): void {
		$this->expectException(ItemNotFoundException::class);

		$this->service->dismiss(404, self::ALICE);
	}

	public function testTheClientIsAnsweredByWhenEachAnnouncementTookEffect(): void {
		// written first and taking effect last, so an answer in the order the
		// rows were stored would have these the other way round
		$starts = $this->announcement('Starts later', 4_500, 9_000, 900);
		$published = $this->announcement('Published first', 0, 0, 1_000);

		$this->assertSame(
			[$published->getId(), $starts->getId()],
			$this->servedTo(self::ALICE, 5_000)
		);
	}

	public function testTheAdminSeesWhatIsShownAndWhatIsNot(): void {
		$this->announcement('Over', 1_000, 2_000);
		$this->announcement('Now', 4_000, 6_000);

		$rows = $this->service->adminList(5_000);

		// newest first, as the page lists them
		$this->assertSame(['Now', 'Over'], array_column($rows, 'text'));
		$this->assertSame([true, false], array_column($rows, 'active'));
	}

	public function testTheAdminListCarriesTheTextAsItWasTypedRatherThanItsHtml(): void {
		$this->announcement('Check your <IfModule>');

		$this->assertSame('Check your <IfModule>', $this->service->adminList(5_000)[0]['text']);
	}

	public function testAnAnnouncementIsWhatWasTypedWithoutItsSurroundingSpace(): void {
		$announcement = $this->service->create("  Maintenance on Sunday \n");

		$this->assertSame('Maintenance on Sunday', $announcement->getText());
		$this->assertSame([$announcement->getId()], $this->servedTo(self::ALICE, 5_000));
	}

	public function testAnEmptyAnnouncementIsRefused(): void {
		$this->expectException(InvalidResourceException::class);

		$this->service->create("  \n ");
	}

	public function testAnAnnouncementLongerThanTheColumnTakesIsRefusedWhole(): void {
		$this->expectException(InvalidResourceException::class);

		$this->service->create(str_repeat('a', Announcement::MAX_TEXT + 1));
	}

	public function testOneBoundWithoutTheOtherIsRefused(): void {
		// Mastodon's own rule: a range is both of its bounds
		$this->expectException(InvalidResourceException::class);

		$this->service->create('Maintenance', '2026-09-12 10:00');
	}

	public function testAWindowThatEndsBeforeItStartsIsRefused(): void {
		$this->expectException(InvalidResourceException::class);

		$this->service->create('Maintenance', '2026-09-12 10:00', '2026-09-12 09:00');
	}

	public function testADateNobodyCanReadIsRefusedRatherThanStoredAsNoBound(): void {
		// stored as "no bound" it would publish to everybody an announcement
		// the admin scheduled for next month
		$this->expectException(InvalidResourceException::class);

		$this->service->create('Maintenance', 'next toosday', '2026-09-12 09:00');
	}

	public function testAWindowIsStoredAsTheAdminGaveIt(): void {
		$announcement = $this->service->create('Maintenance', '2026-09-12 10:00', '2026-09-12 12:00');

		$this->assertSame(strtotime('2026-09-12 10:00'), $announcement->getStartsAt());
		$this->assertSame(strtotime('2026-09-12 12:00'), $announcement->getEndsAt());
		$this->assertFalse($announcement->isAllDay());
	}

	public function testAWholeDayWindowCoversBothOfItsDaysEndToEnd(): void {
		$announcement = $this->service->create('Maintenance', '2026-09-12 10:00', '2026-09-13 11:00', true);

		$this->assertSame(strtotime('2026-09-12 00:00'), $announcement->getStartsAt());
		$this->assertSame(strtotime('2026-09-14 00:00'), $announcement->getEndsAt());
		$this->assertTrue($announcement->isAllDay());

		// the last day is shown all of itself, and the day after is not
		$this->assertTrue($announcement->isActiveAt(strtotime('2026-09-13 23:59')));
		$this->assertFalse($announcement->isActiveAt(strtotime('2026-09-14 00:00')));
	}

	public function testWholeDaysWithoutAWindowClaimsNothing(): void {
		$announcement = $this->service->create('Maintenance', '', '', true);

		$this->assertFalse($announcement->isAllDay());
		$this->assertFalse($announcement->jsonSerialize()['all_day']);
	}

	public function testRemovingAnAnnouncementTakesItFromEverybodyWhoHadReadIt(): void {
		$announcement = $this->announcement('Maintenance on Sunday');
		$this->service->dismiss($announcement->getId(), self::ALICE);

		$this->service->delete($announcement->getId());

		$this->assertSame([], $this->servedTo(self::ALICE, 5_000));
		$this->assertSame([], $this->service->adminList(5_000));
	}

	public function testRemovingSomethingThatIsNotThereIsNotFound(): void {
		$this->expectException(ItemNotFoundException::class);

		$this->service->delete(404);
	}
}
