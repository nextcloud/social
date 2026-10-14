<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use InvalidArgumentException;
use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Identity\CustomHandleService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\Mnemonic;
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
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** The switch for a person's own presence on Bluesky, and the identity the settings show. */
#[AllowMockObjectsWithoutExpectations]
class AtprotoAccountControllerTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private PresenceService&MockObject $presence;
	private Publisher&MockObject $publisher;
	private MoveInService&MockObject $moveIn;
	private InboundMoveService&MockObject $inbound;
	private IdentityService&MockObject $identities;
	private bool $enabled = true;

	protected function setUp(): void {
		$this->presence = $this->createMock(PresenceService::class);
		$this->publisher = $this->createMock(Publisher::class);
		$this->moveIn = $this->createMock(MoveInService::class);
		$this->inbound = $this->createMock(InboundMoveService::class);
		$this->identities = $this->createMock(IdentityService::class);
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
			$this->createStub(IRequest::class), $session, $config, $accounts, $this->identities, $this->publisher,
			$this->createStub(LabelerService::class), $this->createStub(AppPasswordService::class), $this->createStub(AuthorizationServer::class),
			$this->createStub(CustomHandleService::class), $this->createStub(MoveAwayService::class), $this->moveIn,
			$this->inbound, $this->createStub(BridgyTwin::class), $this->createStub(BlueskyBlocks::class), $this->presence,
		);
	}

	/**
	 * Starting a move waits for the other server in the request, so the
	 * route is held to a few tries an hour, read the way the server reads it.
	 */
	public function testStartingAMoveIsRateLimitedPerUser(): void {
		$attributes = (new \ReflectionMethod(AtprotoAccountController::class, 'moveAway'))->getAttributes(UserRateLimit::class);

		$this->assertCount(1, $attributes);
		$limit = $attributes[0]->newInstance();
		$this->assertSame(3, $limit->getLimit());
		$this->assertSame(3600, $limit->getPeriod());
	}

	/**
	 * Moving here signs in to another server with a password, and an
	 * invitation sends a message: both are held to a few an hour.
	 */
	public function testMovingHereIsRateLimitedPerUser(): void {
		foreach (['moveIn', 'moveInvite'] as $route) {
			$attributes = (new \ReflectionMethod(AtprotoAccountController::class, $route))->getAttributes(UserRateLimit::class);
			$this->assertCount(1, $attributes, $route);
			$limit = $attributes[0]->newInstance();
			$this->assertSame([5, 3600], [$limit->getLimit(), $limit->getPeriod()], $route);
		}
	}

	/** Calling a move off asks for the password, as starting it does; a new code is a few times an hour. */
	public function testCallingOffAndANewCodeAreGuarded(): void {
		$cancel = new \ReflectionMethod(AtprotoAccountController::class, 'cancelMoveIn');
		$this->assertCount(1, $cancel->getAttributes(PasswordConfirmationRequired::class));
		$limit = (new \ReflectionMethod(AtprotoAccountController::class, 'newMoveInCode'))->getAttributes(UserRateLimit::class)[0]->newInstance();
		$this->assertSame([3, 3600], [$limit->getLimit(), $limit->getPeriod()]);
	}

	public function testANewCodeSaysWhereItWent(): void {
		$move = new Move(1, self::DID, 'alice', Move::IN, 'https://pds.example.com', '', 'alice.bsky.social', Move::STEP_CODE, Move::WAITING);
		$this->moveIn->method('newCode')->willReturn(['move' => $move, 'sent_to' => 'alice@example.org']);
		$this->moveIn->method('cancel')->willThrowException(new MoveErrorException('Too late', 'move-past-switch'));

		$data = $this->controller()->newMoveInCode()->getData();
		$this->assertSame(['alice@example.org', 'waiting'], [$data['sent_to'], $data['move']['state']]);
		$this->assertSame('move-past-switch', $this->controller()->cancelMoveIn()->getData()['code']);
	}

	/** The decision on missing pictures goes to the move that waits for it. */
	public function testTheDecisionGoesToTheMoveThatWaits(): void {
		$move = new Move(1, self::DID, 'alice', Move::IN, 'https://pds.example.com', '', 'alice.bsky.social', Move::STEP_CODE, Move::NEEDS_DECISION);
		$this->inbound->method('latest')->willReturnOnConsecutiveCalls($move, null);
		$this->moveIn->expects($this->once())->method('decide')->with($move, true)->willReturn($move);

		$this->assertSame('needs_decision', $this->controller()->continueMoveIn(true)->getData()['move']['state']);
		$this->assertSame('no-decision-pending', $this->controller()->continueMoveIn()->getData()['code']);
	}

	/** The move card says how many posts a move here takes off Bluesky, and can have them published again. */
	public function testThePostsOnBlueskyAreToldAndCanBeRepublished(): void {
		$this->moveIn->method('postsOnBluesky')->with('alice')->willReturn(38);
		$this->moveIn->method('republish')->willThrowException(new MoveErrorException('Nothing', 'nothing-to-republish'));

		$data = $this->controller()->move()->getData();
		$this->assertSame([null, 38], [$data['move'], $data['posts_on_bluesky']]);
		$this->assertSame('nothing-to-republish', $this->controller()->republishMovedPosts()->getData()['code']);
	}

	/** Every refusal of a move route carries the code the page explains, next to the sentence. */
	public function testAMoveRouteAnswersAnErrorWithItsCode(): void {
		$this->moveIn->method('start')->willThrowException(new MoveErrorException('The Bluesky server did not let you sign in', 'wrong-password'));
		$this->moveIn->method('code')->willThrowException(new MoveErrorException('That is not a code', 'code-invalid'));

		$refused = $this->controller()->moveIn('alice.bsky.social', 'pw');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		$this->assertSame(['error' => 'The Bluesky server did not let you sign in', 'code' => 'wrong-password'], $refused->getData());
		$this->assertSame('code-invalid', $this->controller()->moveCode('')->getData()['code'] ?? null);

		$this->enabled = false;
		$this->assertSame([Http::STATUS_NOT_FOUND, 'bluesky-off'], [$this->controller()->moveIn('a', 'b')->getStatus(), $this->controller()->moveIn('a', 'b')->getData()['code']]);
	}

	private static function identity(string $state): Identity {
		return new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', '', '', $state, '', 0);
	}

	public function testSwitchingOffAnswersTheIdentityAsOff(): void {
		$this->presence->expects($this->once())->method('switchOff')->willReturn(self::identity(Identity::STATE_DEACTIVATED));
		$this->presence->expects($this->never())->method('switchOn');
		$this->publisher->expects($this->never())->method('publishProfile');

		$data = $this->controller()->state(false)->getData();

		$this->assertSame(['deactivated', false, self::DID, 'alice.social.test'], [$data['state'], $data['active'], $data['did'], $data['handle']]);
	}

	public function testSwitchingOnAnswersTheIdentityAsOnWithTheProfileAsItIsNow(): void {
		$this->presence->expects($this->once())->method('switchOn')->willReturn(self::identity(Identity::STATE_ACTIVE));
		$this->publisher->expects($this->once())->method('publishProfile');

		$data = $this->controller()->state(true)->getData();

		$this->assertSame(['active', true], [$data['state'], $data['active']]);
	}

	public function testNothingToSwitchIsNotFound(): void {
		$this->presence->method('switchOff')->willThrowException(new InvalidArgumentException('This account has no Bluesky identity'));
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->state(false)->getStatus());

		$this->enabled = false;
		$this->presence->expects($this->never())->method('switchOn');
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->state(true)->getStatus());
	}

	/** The settings read `active` to draw the switch. */
	public function testTheExportSaysWhetherTheIdentityIsOn(): void {
		$this->assertTrue(AtprotoAccountController::export(self::identity(Identity::STATE_ACTIVE))['active']);
		$this->assertFalse(AtprotoAccountController::export(self::identity(Identity::STATE_DEACTIVATED))['active']);
	}

	/**
	 * The phrase comes with the key it stands for, written as goat and the
	 * reference PDS take a key, and the did:key the directory lists.
	 */
	public function testANewPhraseComesWithItsKeyInTheFormsOtherToolsTake(): void {
		[$phrase, $key] = Mnemonic::generate();
		$this->identities->method('forActor')->willReturn(self::identity(Identity::STATE_ACTIVE));
		$this->identities->expects($this->once())->method('issueRecoveryKey')->willReturn($phrase);
		$this->identities->method('getByDid')->willReturn(self::identity(Identity::STATE_ACTIVE));

		$data = $this->controller()->recovery()->getData();

		$this->assertSame($phrase, $data['phrase']);
		$this->assertSame(['did_key' => $key->didKey(), 'multibase' => $key->multibase(), 'hex' => bin2hex($key->secret())], $data['key']);
	}
}
