<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Move\StaleMoves;
use OCA\Social\Cron\AtprotoMove;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A move whose job died while it ran is queued again once, then ended so
 * the person can act on it; a move away that died while the DID was handed
 * over goes on from where the directory says it is.
 */
#[AllowMockObjectsWithoutExpectations]
class StaleMovesTest extends TestCase {
	private const DID = 'did:plc:z72i7hdynmk6r22z27h6tvur';
	private const NOW = 1760000000;

	/** @var list<Move> what the table answers as stale */
	private array $stale = [];
	/** @var array<int, Move> what was written, by id */
	private array $written = [];
	/** @var list<array{0: string, 1: mixed}> */
	private array $queued = [];
	/** @var list<int> the moves a job waits in the queue for */
	private array $waiting = [];
	private int $before = 0;
	/** where the directory says the DID's PDS is; null when it cannot be asked */
	private ?string $pointsAt = 'https://social.test';
	private StaleMoves $staleMoves;

	protected function setUp(): void {
		$moves = $this->createMock(AtprotoMoveRequest::class);
		$moves->method('getStale')->willReturnCallback(function (int $before): array {
			$this->before = $before;

			return $this->stale;
		});
		$moves->method('update')->willReturnCallback(function (Move $move): void {
			$this->written[$move->id] = clone $move;
		});
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('has')->willReturnCallback(fn (string $job, mixed $argument): bool => $job === AtprotoMove::class && in_array($argument['move'] ?? 0, $this->waiting, true));
		$jobs->method('add')->willReturnCallback(function (string $job, mixed $argument): void {
			$this->queued[] = [$job, $argument];
		});
		$plc = $this->createMock(PlcClient::class);
		$plc->method('data')->willReturnCallback(fn (): array => $this->pointsAt !== null
			? ['services' => ['atproto_pds' => ['type' => 'AtprotoPersonalDataServer', 'endpoint' => $this->pointsAt]]]
			: throw new AtprotoException('PLC directory could not be reached'));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->staleMoves = new StaleMoves($moves, $jobs, $plc, $time, new NullLogger());
	}

	private static function move(string $direction, string $step, array $progress = []): Move {
		return new Move(7, self::DID, 'alice', $direction, 'https://pds.example.com/', '', 'alice.bsky.social', $step, Move::RUNNING, 'sealed', $progress, '', self::NOW - 7200, self::NOW - 7200);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function directions(): array {
		return [
			'here' => [Move::IN, Move::STEP_BLOBS],
			'away' => [Move::AWAY, Move::STEP_BLOBS],
			'here, driven by a tool' => [Move::INBOUND, Move::STEP_FOLLOWS],
		];
	}

	/** A running move not written to for an hour and with no job waiting is queued again, once. */
	#[DataProvider('directions')]
	public function testAStoppedMoveIsQueuedAgainOnce(string $direction, string $step): void {
		$this->stale = [self::move($direction, $step)];

		$this->assertSame(1, $this->staleMoves->recover());

		$this->assertSame(self::NOW - StaleMoves::AFTER, $this->before);
		$this->assertSame([[AtprotoMove::class, ['move' => 7]]], $this->queued);
		$this->assertSame([Move::RUNNING, 1], [$this->written[7]->state, $this->written[7]->progress['resumed']]);
	}

	/** A move whose job still waits in the queue is only waiting for cron. */
	public function testAMoveWhoseJobWaitsIsLeft(): void {
		$this->stale = [self::move(Move::IN, Move::STEP_BLOBS)];
		$this->waiting = [7];

		$this->assertSame(0, $this->staleMoves->recover());

		$this->assertSame([[], []], [$this->queued, $this->written]);
	}

	/** Stopped again, a move fails with a code, so the person can try again or call it off. */
	public function testAMoveThatStopsAgainFails(): void {
		$this->stale = [self::move(Move::IN, Move::STEP_BLOBS, ['resumed' => StaleMoves::RESUMES])];

		$this->staleMoves->recover();

		$this->assertSame([], $this->queued);
		$this->assertSame([Move::FAILED, 'move-interrupted'], [$this->written[7]->state, $this->written[7]->progress['code']]);
	}

	/** Going on with a move forgets how often it was picked up. */
	public function testGoingOnForgetsTheResumes(): void {
		$move = self::move(Move::IN, Move::STEP_BLOBS, ['resumed' => 1, 'code' => 'move-interrupted']);
		$move->state = Move::FAILED;

		$move->resume();

		$this->assertSame([], $move->progress);
	}

	/** A move away that stopped once the directory names the other server goes on from the activation. */
	public function testAMoveAwayPastTheHandOverGoesOnFromTheActivation(): void {
		$this->stale = [self::move(Move::AWAY, Move::STEP_IDENTITY)];
		$this->pointsAt = 'https://pds.example.com';

		$this->staleMoves->recover();

		$this->assertSame([Move::STEP_ACTIVATE, 'identity_switched'], [$this->written[7]->step, $this->written[7]->progress['stage']]);
		$this->assertCount(1, $this->queued);
	}

	/** One the directory does not name the other server for is started again at the hand-over; failed, it can be called off. */
	public function testAMoveAwayBeforeTheHandOverStaysThere(): void {
		$this->stale = [self::move(Move::AWAY, Move::STEP_IDENTITY, ['resumed' => 1])];

		$this->staleMoves->recover();

		$this->assertSame([Move::STEP_IDENTITY, Move::FAILED, 'repo_copied'], [$this->written[7]->step, $this->written[7]->state, $this->written[7]->progress['stage']]);
	}

	/** While the directory cannot be asked, a move away at the hand-over is left for the next run. */
	public function testAMoveAwayIsLeftWhileTheDirectoryCannotBeAsked(): void {
		$this->stale = [self::move(Move::AWAY, Move::STEP_IDENTITY)];
		$this->pointsAt = null;

		$this->assertSame(0, $this->staleMoves->recover());

		$this->assertSame([[], []], [$this->queued, $this->written]);
	}

	/** Driven by a tool, a move waits for the person's decision again, or is done once the account is active. */
	public function testAMoveDrivenByAToolEndsInAStateThePersonCanActOn(): void {
		$this->stale = [self::move(Move::INBOUND, Move::STEP_BLOBS, ['resumed' => 1])];
		$this->staleMoves->recover();
		$this->assertSame([Move::NEEDS_DECISION, 'blobs-missing'], [$this->written[7]->state, $this->written[7]->progress['code']]);

		$this->stale = [self::move(Move::INBOUND, Move::STEP_POSTS, ['resumed' => 1])];
		$this->staleMoves->recover();
		$this->assertSame([Move::DONE, Move::STEP_DONE], [$this->written[7]->state, $this->written[7]->step]);
	}
}
