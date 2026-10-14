<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Move\BlueskyAfterMoveOut;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The Bluesky identity of an account whose Fediverse half moves out. */
#[AllowMockObjectsWithoutExpectations]
class BlueskyAfterMoveOutTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private IdentityService&MockObject $identities;
	private ?Identity $identity;
	private ?Move $move = null;
	private BlueskyAfterMoveOut $after;
	private Person $alice;

	protected function setUp(): void {
		$this->identity = self::identity(Identity::STATE_ACTIVE);
		$this->identities = $this->createMock(IdentityService::class);
		$this->identities->method('forActor')->willReturnCallback(fn (): ?Identity => $this->identity);
		$moves = $this->createMock(AtprotoMoveRequest::class);
		$moves->method('latestOfDid')->willReturnCallback(fn (): ?Move => $this->move);
		$this->after = new BlueskyAfterMoveOut($this->identities, $moves, new NullLogger());
		$this->alice = (new Person())->setUserId('alice');
	}

	private static function identity(string $state): Identity {
		return new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', '', '', $state, '', 0);
	}

	private function moveAway(string $state): Move {
		return new Move(1, self::DID, 'alice', Move::AWAY, 'https://pds.example.com', '', 'alice.pds.example.com', Move::STEP_BLOBS, $state);
	}

	public function testByDefaultTheIdentityIsSwitchedOffThroughTheIdentityService(): void {
		$this->identities->expects($this->once())->method('deactivate')->with($this->identity);

		$this->after->assertPossible($this->alice, BlueskyAfterMoveOut::DEACTIVATE);
		$this->assertSame('deactivated', $this->after->apply($this->alice, BlueskyAfterMoveOut::DEACTIVATE));
	}

	public function testKeepingLeavesItAsItIs(): void {
		$this->identities->expects($this->never())->method('deactivate');

		$this->assertSame('kept', $this->after->apply($this->alice, BlueskyAfterMoveOut::KEEP));
	}

	public function testMovingNeedsTheBlueskyMoveDoneOrUnderWay(): void {
		foreach ([null, $this->moveAway(Move::FAILED), $this->moveAway(Move::CANCELLED)] as $move) {
			$this->move = $move;
			try {
				$this->after->assertPossible($this->alice, BlueskyAfterMoveOut::MOVE);
				$this->fail('moved out with the Bluesky account ' . ($move?->state ?? 'never moved'));
			} catch (MoveErrorException $e) {
				$this->assertSame('bluesky-move-first', $e->errorCode);
			}
		}
		foreach ([Move::RUNNING, Move::NEEDS_DECISION, Move::DONE] as $state) {
			$this->move = $this->moveAway($state);
			$this->after->assertPossible($this->alice, BlueskyAfterMoveOut::MOVE);
		}
		$this->identities->expects($this->never())->method('deactivate');
		$this->assertSame('moving', $this->after->apply($this->alice, BlueskyAfterMoveOut::MOVE));
	}

	public function testAnAccountThatMovedAwayOrHasNoIdentityMayChooseAnything(): void {
		$this->identity = self::identity(Identity::STATE_MOVED_AWAY);
		$this->after->assertPossible($this->alice, BlueskyAfterMoveOut::MOVE);

		$this->identity = null;
		$this->after->assertPossible($this->alice, BlueskyAfterMoveOut::MOVE);
		$this->assertSame('none', $this->after->apply($this->alice, BlueskyAfterMoveOut::DEACTIVATE));
	}

	public function testAnUnknownChoiceIsRefused(): void {
		$this->expectException(MoveErrorException::class);
		$this->after->assertPossible($this->alice, 'delete');
	}
}
