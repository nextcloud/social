<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ScheduledStatusesRequest;
use OCA\Social\Model\Client\ScheduledStatus;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Paging the waiting posts, against the real database.
 *
 * The list is ordered by `scheduled_at` and the cursor a caller sends is a row
 * id — two different orders, because an id is creation order and the schedule
 * can be moved afterwards. Comparing the one while ordering by the other
 * repeats rows across pages and hides others entirely, and no mocked query
 * builder can show it: what is being asserted is what the database returns for
 * a given WHERE and ORDER BY.
 *
 * Every row here is written through the production request class and removed
 * again in tearDown, so the suite stays safe against an instance with data on
 * it.
 */
class ScheduledStatusPagingTest extends TestCase {
	private const ACTOR = 'https://cloud.example.org/sched-paging/users/author';
	private const OTHER = 'https://cloud.example.org/sched-paging/users/stranger';

	/** 2030, so nothing here is ever due while the suite runs. */
	private const BASE_TIME = 1893456000;

	private ScheduledStatusesRequest $request;

	protected function setUp(): void {
		parent::setUp();
		$this->request = Server::get(ScheduledStatusesRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([self::ACTOR, self::OTHER] as $actor) {
			$this->request->deleteRelatedId($actor);
		}
	}

	/**
	 * Writes a waiting post and answers its row id.
	 *
	 * `$minutes` is the offset from `BASE_TIME`, so a test reads as the order
	 * it is setting up rather than as a column of timestamps.
	 */
	private function waiting(int $minutes, string $actor = self::ACTOR): int {
		$scheduled = new ScheduledStatus();
		$scheduled->setActorId($actor)
			->setScheduledAt(self::BASE_TIME + ($minutes * 60))
			->setParams(['status' => 'at +' . $minutes]);

		return $this->request->save($scheduled);
	}

	/**
	 * @param ScheduledStatus[] $page
	 * @return int[]
	 */
	private function ids(array $page): array {
		return array_map(static fn (ScheduledStatus $one): int => $one->getId(), $page);
	}

	/**
	 * The case from the report: ids and times in different orders.
	 *
	 * Rows at +10, +8 and +9 minutes are created in that order, so the ids
	 * ascend while the times do not. The first page is the two soonest — the
	 * +8 and +9 rows — and continuing from the last of them must hand back the
	 * +10 row and nothing else. Comparing ids instead gave back the +8 row a
	 * second time.
	 */
	public function testASecondPageRepeatsNothingWhenIdsAndTimesDisagree(): void {
		$late = $this->waiting(10);
		$early = $this->waiting(8);
		$middle = $this->waiting(9);

		$first = $this->ids($this->request->getByActor(self::ACTOR, 2));
		$this->assertSame([$early, $middle], $first, 'the page is the two soonest');

		$second = $this->ids($this->request->getByActor(self::ACTOR, 2, 0, end($first)));
		$this->assertSame([$late], $second, 'the rest of the list, once each');
	}

	/** Walking the whole list a page at a time reaches every row exactly once. */
	public function testEveryRowIsReachedOnceWalkingForwards(): void {
		$ids = [
			$this->waiting(50),
			$this->waiting(10),
			$this->waiting(40),
			$this->waiting(20),
			$this->waiting(30),
		];

		$seen = [];
		$cursor = 0;
		for ($page = 0; $page < 10; $page++) {
			$rows = $this->ids($this->request->getByActor(self::ACTOR, 2, 0, $cursor));
			if ($rows === []) {
				break;
			}

			$seen = array_merge($seen, $rows);
			$cursor = end($rows);
		}

		sort($seen);
		sort($ids);
		$this->assertSame($ids, $seen, 'each row once, none missed');
	}

	/**
	 * `max_id` asks for what comes *before* the cursor, which is the page
	 * ending at it — not the earliest page there is.
	 */
	public function testMaxIdAnswersThePageEndingAtTheCursor(): void {
		$this->waiting(10);
		$this->waiting(20);
		$third = $this->waiting(30);
		$fourth = $this->waiting(40);
		$fifth = $this->waiting(50);

		$page = $this->ids($this->request->getByActor(self::ACTOR, 2, $fifth));

		$this->assertSame([$third, $fourth], $page, 'the two rows before the cursor, in order');
	}

	/** `since_id` and `min_id` are the same question and answer the same way. */
	public function testSinceIdAndMinIdAgree(): void {
		$first = $this->waiting(10);
		$second = $this->waiting(20);

		$bySince = $this->ids($this->request->getByActor(self::ACTOR, 20, 0, 0, $first));
		$byMin = $this->ids($this->request->getByActor(self::ACTOR, 20, 0, $first));

		$this->assertSame([$second], $bySince);
		$this->assertSame($bySince, $byMin);
	}

	/**
	 * Moving a post across another one keeps paging stable: the cursor means
	 * a place in the order, so the row that is now after it is what follows.
	 */
	public function testReschedulingAcrossAnotherRowKeepsPagingStable(): void {
		$first = $this->waiting(10);
		$second = $this->waiting(20);
		$third = $this->waiting(30);

		// the last row is moved to the front of the queue
		$this->request->reschedule($third, self::ACTOR, self::BASE_TIME + 60);

		$page = $this->ids($this->request->getByActor(self::ACTOR, 2));
		$this->assertSame([$third, $first], $page, 'soonest first, with the moved row leading');

		$next = $this->ids($this->request->getByActor(self::ACTOR, 2, 0, end($page)));
		$this->assertSame([$second], $next, 'and the one still behind it');
	}

	/**
	 * A cursor naming a row that is gone — published or cancelled between two
	 * pages, which for a scheduled post is ordinary — still answers, rather
	 * than returning nothing and stopping a client mid-list.
	 */
	public function testACursorWhoseRowHasGoneStillAnswers(): void {
		$first = $this->waiting(10);
		$second = $this->waiting(20);
		$this->request->delete($first, self::ACTOR);

		$page = $this->ids($this->request->getByActor(self::ACTOR, 20, 0, $first));

		$this->assertSame([$second], $page);
	}

	/** The owner is in the statement: another account's rows are never paged into this list. */
	public function testAnotherAccountsRowsAreNotPaged(): void {
		$mine = $this->waiting(20);
		$this->waiting(10, self::OTHER);

		$this->assertSame([$mine], $this->ids($this->request->getByActor(self::ACTOR, 20)));
	}
}
