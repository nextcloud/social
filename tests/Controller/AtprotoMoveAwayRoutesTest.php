<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Identity\CustomHandleService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PresenceService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Move\BridgyTwin;
use OCA\Social\Atproto\Move\InboundMoveService;
use OCA\Social\Atproto\Move\MoveAwayService;
use OCA\Social\Atproto\Move\MoveInService;
use OCA\Social\Atproto\OAuth\AuthorizationServer;
use OCA\Social\Atproto\Publisher\BlueskyBlocks;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Controller\AtprotoAccountController;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The routes of moving a Bluesky account away, as the Migration page reads
 * them: every error with its code beside the sentence.
 */
#[AllowMockObjectsWithoutExpectations]
class AtprotoMoveAwayRoutesTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private MoveAwayService&MockObject $moveAway;
	private InboundMoveService&MockObject $inbound;
	private IdentityService&MockObject $identities;
	private bool $enabled = true;
	private ?Identity $identity;

	protected function setUp(): void {
		$this->moveAway = $this->createMock(MoveAwayService::class);
		$this->inbound = $this->createMock(InboundMoveService::class);
		$this->identities = $this->createMock(IdentityService::class);
		$this->identity = self::identity();
		$this->identities->method('forActor')->willReturnCallback(fn (): ?Identity => $this->identity);
	}

	private static function identity(string $recovery = ''): Identity {
		return new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', '', $recovery, Identity::STATE_ACTIVE, '', 0);
	}

	private function controller(): AtprotoAccountController {
		$session = $this->createStub(IUserSession::class);
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($user);
		$config = $this->createStub(AtprotoConfig::class);
		$config->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$accounts = $this->createStub(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturn((new Person())->setUserId('alice'));

		return new AtprotoAccountController(
			$this->createStub(IRequest::class), $session, $config, $accounts, $this->identities, $this->createStub(Publisher::class),
			$this->createStub(LabelerService::class), $this->createStub(AppPasswordService::class), $this->createStub(AuthorizationServer::class),
			$this->createStub(CustomHandleService::class), $this->moveAway, $this->createStub(MoveInService::class),
			$this->inbound, $this->createStub(BridgyTwin::class), $this->createStub(BlueskyBlocks::class), $this->createStub(PresenceService::class),
		);
	}

	public function testARefusedStartAnswersItsCode(): void {
		$this->moveAway->method('start')->willThrowException(new MoveErrorException('That server needs an invite code', 'invite-code-required'));

		$response = $this->controller()->moveAway('pds.example.com', 'alice.pds.example.com', 'a@example.org', 'pw');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'That server needs an invite code', 'code' => 'invite-code-required'], $response->getData());
	}

	public function testAnUnreachableServerIsCodedToo(): void {
		$this->moveAway->method('retry')->willThrowException(new AtprotoException('The PDS at https://x could not be reached: timeout'));

		$this->assertSame('pds-unreachable', $this->controller()->retryMove()->getData()['code']);
	}

	public function testNoIdentityAndBlueskyOffHaveCodes(): void {
		$this->identity = null;
		$response = $this->controller()->moveAway('pds.example.com', 'alice.pds.example.com', 'a@example.org', 'pw');
		$this->assertSame([Http::STATUS_NOT_FOUND, 'no-identity'], [$response->getStatus(), $response->getData()['code']]);

		$this->enabled = false;
		$response = $this->controller()->move();
		$this->assertSame([Http::STATUS_NOT_FOUND, 'bluesky-off'], [$response->getStatus(), $response->getData()['code']]);
	}

	public function testTheStatusSaysWhoControlsTheAccountAfterwards(): void {
		$this->identity = self::identity('did:key:zRecovery');
		$this->inbound->method('latest')->willReturn(new Move(1, self::DID, 'alice', Move::AWAY, 'https://pds.example.com', '', 'alice.pds.example.com', Move::STEP_DONE, Move::DONE));

		$data = $this->controller()->move()->getData();

		$this->assertSame([true, ['recovery', 'new_server'], 'pds.example.com'], [$data['has_recovery_key'], $data['rotation_keys'], $data['new_host']]);
	}

	public function testWithoutRecoveryIsPassedOn(): void {
		$this->moveAway->expects($this->once())->method('start')
			->with('alice', $this->anything(), 'pds.example.com', 'alice.pds.example.com', 'a@example.org', 'pw', '', true)
			->willReturn(new Move(1, self::DID, 'alice', Move::AWAY, 'https://pds.example.com', '', 'alice.pds.example.com', Move::STEP_REPO, Move::RUNNING));

		$this->controller()->moveAway('pds.example.com', 'alice.pds.example.com', 'a@example.org', 'pw', '', true);
	}

	public function testNothingToStartAgainIsCoded(): void {
		$this->moveAway->method('latest')->willReturn(new Move(1, self::DID, 'alice', Move::AWAY, 'https://pds.example.com', '', 'alice.pds.example.com', Move::STEP_REPO, Move::DONE));
		$this->moveAway->method('retry')->willThrowException(new MoveErrorException('There is no failed move to start again', 'no-failed-move'));

		$this->assertSame('no-failed-move', $this->controller()->retryMove()->getData()['code']);
	}
}
