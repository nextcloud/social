<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\StrikesRequest;
use OCA\Social\Model\Moderation;
use OCA\Social\Model\Strike;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The moderation history against the real database.
 *
 * `social_moderation` keeps one row an account, replaced by the next decision
 * and deleted by a lift; this table keeps every decision ever taken. That
 * difference is the whole point of the table, and it is a property of what the
 * SQL does rather than of what the service intends — an accidental unique key
 * on the actor, or a delete on lift, would leave the service looking right.
 */
class StrikeHistoryTest extends TestCase {
	private const ALICE = 'https://cloud.example.org/strikes/users/alice';
	private const BOB = 'https://remote.example/strikes/users/bob';

	private StrikesRequest $strikesRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->strikesRequest = Server::get(StrikesRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([self::ALICE, self::BOB] as $actorId) {
			$this->strikesRequest->deleteForActor($actorId);
		}
	}

	private function record(string $actorId, string $action, string $text = '', int $reportId = 0): void {
		$this->strikesRequest->save(new Strike($actorId, $action, $text, 'mod', $reportId, time()));
	}

	public function testEveryDecisionIsKept(): void {
		$this->record(self::BOB, Strike::WARNING, 'first time');
		$this->record(self::BOB, Moderation::SILENCE, 'again');
		$this->record(self::BOB, Moderation::SUSPEND, 'enough');

		$history = $this->strikesRequest->getForActor(self::BOB);

		$this->assertCount(3, $history, 'a decision replaced an earlier one');
		$this->assertSame(
			[Moderation::SUSPEND, Moderation::SILENCE, Strike::WARNING],
			array_map(static fn (Strike $strike): string => $strike->getAction(), $history),
			'newest first, which is the order a moderator reads a history in'
		);
	}

	public function testWhatWasWrittenComesBackWhole(): void {
		$this->record(self::BOB, Moderation::SILENCE, 'spam, repeatedly', 7);

		$strike = $this->strikesRequest->getForActor(self::BOB)[0];

		$this->assertSame(self::BOB, $strike->getActorId());
		$this->assertSame(Moderation::SILENCE, $strike->getAction());
		$this->assertSame('spam, repeatedly', $strike->getText());
		$this->assertSame('mod', $strike->getModerator());
		$this->assertSame(7, $strike->getReportId());
		$this->assertGreaterThan(0, $strike->getId());
		$this->assertGreaterThan(0, $strike->getCreation());
	}

	/** The history of one account is the history of one account. */
	public function testAnAccountsHistoryIsItsOwn(): void {
		$this->record(self::ALICE, Strike::WARNING);
		$this->record(self::BOB, Moderation::SILENCE);

		$this->assertCount(1, $this->strikesRequest->getForActor(self::ALICE));
		$this->assertSame(
			Moderation::SILENCE, $this->strikesRequest->getForActor(self::BOB)[0]->getAction()
		);
	}

	public function testTheCountsForAPageComeBackTogether(): void {
		$this->record(self::BOB, Strike::WARNING);
		$this->record(self::BOB, Moderation::SILENCE);

		$counts = $this->strikesRequest->countForActors([self::ALICE, self::BOB]);

		$this->assertSame([self::BOB => 2], $counts, 'an account with no history is not in the answer');
	}

	public function testCountingNobodyAsksNothing(): void {
		$this->assertSame([], $this->strikesRequest->countForActors([]));
	}

	/** A history is bounded: an account under a script has no useful tail. */
	public function testTheHistoryIsBounded(): void {
		for ($i = 0; $i < 5; $i++) {
			$this->record(self::BOB, Strike::WARNING, 'warning ' . $i);
		}

		$this->assertCount(2, $this->strikesRequest->getForActor(self::BOB, 2));
	}

	public function testAnAccountWithNoHistoryHasNone(): void {
		$this->assertSame([], $this->strikesRequest->getForActor(self::ALICE));
	}

	/** Only the purge of the account itself takes its history. */
	public function testForgettingAnAccountTakesItsHistoryAndNobodyElses(): void {
		$this->record(self::ALICE, Strike::WARNING);
		$this->record(self::BOB, Moderation::SILENCE);

		$this->strikesRequest->deleteForActor(self::BOB);

		$this->assertSame([], $this->strikesRequest->getForActor(self::BOB));
		$this->assertCount(1, $this->strikesRequest->getForActor(self::ALICE));
	}
}
