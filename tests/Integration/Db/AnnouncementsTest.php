<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\AnnouncementsRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\Announcement;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Announcements against the real database.
 *
 * Two things here cannot be tested anywhere else. The first is the window:
 * `getActive()` compares both bounds in the statement, so whether an
 * announcement outside its range is served is decided by SQL and by how each
 * database compares a DATETIME column with a bound parameter — a mocked query
 * builder proves nothing about either.
 *
 * The second is the unique index on (account, announcement): `dismiss()` makes
 * dismissing twice a no-op by letting the constraint refuse the second row and
 * swallowing that one reason, and only a real index refuses it.
 */
class AnnouncementsTest extends TestCase {
	private const ALICE = 'https://cloud.example.org/ann/users/alice';
	private const BOB = 'https://cloud.example.org/ann/users/bob';

	/** what the tests write, so cleanup also catches an aborted run */
	private const TEXTS = ['ann-always', 'ann-over', 'ann-not-yet', 'ann-now'];

	private AnnouncementsRequest $announcements;

	protected function setUp(): void {
		parent::setUp();
		$this->announcements = Server::get(AnnouncementsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ($this->announcements->getAll() as $announcement) {
			if (in_array($announcement->getText(), self::TEXTS, true)) {
				$this->announcements->delete($announcement->getId());
			}
		}

		$this->announcements->deleteRelatedId(self::ALICE);
		$this->announcements->deleteRelatedId(self::BOB);
	}

	private function given(string $text, int $startsAt = 0, int $endsAt = 0): Announcement {
		$announcement = (new Announcement())
			->setText($text)
			->setStartsAt($startsAt)
			->setEndsAt($endsAt);
		$this->announcements->save($announcement);

		return $announcement;
	}

	/** @return string[] the texts the active read returns, of ours only */
	private function activeTexts(?int $now = null): array {
		$texts = [];
		foreach ($this->announcements->getActive($now) as $announcement) {
			if (in_array($announcement->getText(), self::TEXTS, true)) {
				$texts[] = $announcement->getText();
			}
		}

		return $texts;
	}

	public function testAnAnnouncementOutsideItsWindowIsNotReturnedByTheQuery(): void {
		$now = time();
		$this->given('ann-always');
		$this->given('ann-over', $now - 7200, $now - 3600);
		$this->given('ann-not-yet', $now + 3600, $now + 7200);
		$this->given('ann-now', $now - 60, $now + 3600);

		$this->assertSame(['ann-always', 'ann-now'], $this->activeTexts($now));

		// and nothing was deleted to make that true: the rows are all still
		// there for the administration page
		$this->assertCount(4, array_filter(
			$this->announcements->getAll(),
			static fn (Announcement $a): bool => in_array($a->getText(), self::TEXTS, true)
		));
	}

	public function testTheWindowOpensAtItsStartAndClosesAtItsEnd(): void {
		$now = time();
		$this->given('ann-now', $now, $now + 3600);

		$this->assertSame([], $this->activeTexts($now - 1));
		$this->assertSame(['ann-now'], $this->activeTexts($now));
		$this->assertSame(['ann-now'], $this->activeTexts($now + 3599));
		$this->assertSame([], $this->activeTexts($now + 3600));
	}

	public function testWhatWasStoredIsWhatComesBack(): void {
		$now = time();
		$announcement = $this->given('ann-now', $now - 60, $now + 3600);

		$stored = $this->announcements->getById($announcement->getId());

		$this->assertSame('ann-now', $stored->getText());
		$this->assertSame($now - 60, $stored->getStartsAt());
		$this->assertSame($now + 3600, $stored->getEndsAt());
		$this->assertGreaterThan(0, $stored->getPublishedAt());
		$this->assertGreaterThan(0, $stored->getUpdatedAt());
	}

	public function testOneAccountsDismissalIsNotAnother(): void {
		$announcement = $this->given('ann-always');
		$this->announcements->dismiss($announcement->getId(), self::ALICE);

		$this->assertSame(
			[$announcement->getId()],
			$this->announcements->dismissedBy(self::ALICE, [$announcement->getId()])
		);
		$this->assertSame([], $this->announcements->dismissedBy(self::BOB, [$announcement->getId()]));
	}

	public function testDismissingTwiceIsANoOpAndNotASecondRow(): void {
		// the unique index on (account, announcement) is what says so
		$announcement = $this->given('ann-always');

		$this->announcements->dismiss($announcement->getId(), self::ALICE);
		$this->announcements->dismiss($announcement->getId(), self::ALICE);

		$this->assertSame(
			[$announcement->getId()],
			$this->announcements->dismissedBy(self::ALICE, [$announcement->getId()])
		);
	}

	public function testRemovingAnAnnouncementTakesTheDismissalsWithIt(): void {
		$announcement = $this->given('ann-always');
		$this->announcements->dismiss($announcement->getId(), self::ALICE);

		$this->announcements->delete($announcement->getId());

		$this->assertSame([], $this->announcements->dismissedBy(self::ALICE, [$announcement->getId()]));
		$this->expectException(ItemNotFoundException::class);
		$this->announcements->getById($announcement->getId());
	}

	public function testRemovingSomethingThatIsNotThereSaysSoRatherThanDeletingNothingQuietly(): void {
		$this->expectException(ItemNotFoundException::class);

		$this->announcements->delete(0);
	}

	public function testAnAccountThatIsDeletedLeavesNoDismissalsBehind(): void {
		$announcement = $this->given('ann-always');
		$this->announcements->dismiss($announcement->getId(), self::ALICE);

		$this->announcements->deleteRelatedId(self::ALICE);

		$this->assertSame([], $this->announcements->dismissedBy(self::ALICE, [$announcement->getId()]));
		// the announcement is the instance's and stays
		$this->assertSame('ann-always', $this->announcements->getById($announcement->getId())->getText());
	}
}
