<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Move\InboundMoveService;
use OCA\Social\Atproto\Move\MoveAwayService;
use OCA\Social\Atproto\Move\MoveInService;
use OCA\Social\Atproto\Move\PostRepublisher;
use OCA\Social\Cron\AtprotoMove;
use OCA\Social\Db\AtprotoMoveRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * One job carries a move at a time: a second one, queued again while the
 * first still runs, finds the move's lock held and leaves it.
 */
#[AllowMockObjectsWithoutExpectations]
class AtprotoMoveTest extends TestCase {
	private MoveInService&MockObject $in;
	private MoveAwayService&MockObject $away;
	/** @var array<string, true> the locks held */
	private array $held = [];
	private AtprotoMove $job;

	protected function setUp(): void {
		parent::setUp();
		$moves = $this->createMock(AtprotoMoveRequest::class);
		$moves->method('get')->willReturnCallback(static fn (int $id): ?Move => $id === 7
			? new Move(7, 'did:plc:z72i7hdynmk6r22z27h6tvur', 'alice', Move::IN, 'https://old.example.com', '', 'alice.bsky.social', Move::STEP_POSTS, Move::RUNNING)
			: ($id === 8 ? new Move(8, 'did:plc:z72i7hdynmk6r22z27h6tvur', 'alice', Move::AWAY, 'https://new.example.com', '', 'alice.new.example.com', Move::STEP_BLOBS, Move::RUNNING) : null));
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willReturnCallback(function (string $path, int $type): void {
			$this->assertSame(ILockingProvider::LOCK_EXCLUSIVE, $type);
			if (isset($this->held[$path])) {
				throw new LockedException($path);
			}
			$this->held[$path] = true;
		});
		$locks->method('releaseLock')->willReturnCallback(function (string $path): void {
			unset($this->held[$path]);
		});
		$this->in = $this->createMock(MoveInService::class);
		$this->away = $this->createMock(MoveAwayService::class);
		$this->job = new AtprotoMove($this->createStub(ITimeFactory::class), $moves, $this->away, $this->in, $this->createMock(InboundMoveService::class), $this->createMock(PostRepublisher::class), $locks, new NullLogger());
	}

	private function runJob(mixed $argument): void {
		(new \ReflectionMethod(AtprotoMove::class, 'run'))->invoke($this->job, $argument);
	}

	public function testTheMoveIsRunHoldingItsLock(): void {
		$this->in->expects($this->once())->method('run')->willReturnCallback(function (Move $move): void {
			$this->assertSame([AtprotoMove::lockOf(7) => true], $this->held);
		});

		$this->runJob(['move' => 7]);

		$this->assertSame([], $this->held, 'released once the run ends');
	}

	public function testASecondJobLeavesAMoveTheFirstStillCarries(): void {
		$this->in->expects($this->never())->method('run');
		$this->held[AtprotoMove::lockOf(7)] = true;

		$this->runJob(['move' => 7]);

		$this->assertSame([AtprotoMove::lockOf(7) => true], $this->held, 'the first job\'s lock is left alone');
	}

	public function testTheLockIsReleasedWhenTheRunThrows(): void {
		$this->away->method('run')->willThrowException(new \RuntimeException('out of memory'));

		try {
			$this->runJob(['move' => 8]);
			$this->fail('the run threw');
		} catch (\RuntimeException) {
		}

		$this->assertSame([], $this->held);
	}
}
