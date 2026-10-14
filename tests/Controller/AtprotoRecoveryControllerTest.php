<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Atproto\Identity\DidControlService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Service\BlueskyArchiveService;
use OCA\Social\Controller\AtprotoRecoveryController;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Response\StreamedPiecesResponse;
use OCA\Social\Service\AccountService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The download of a person's Bluesky account: only their own, only after
 * the password, a few an hour, sent in pieces from the finished file.
 */
#[AllowMockObjectsWithoutExpectations]
class AtprotoRecoveryControllerTest extends TestCase {
	private IdentityService&MockObject $identities;
	private BlueskyArchiveService&MockObject $archive;
	private DidControlService&MockObject $control;

	protected function setUp(): void {
		$this->identities = $this->createMock(IdentityService::class);
		$this->archive = $this->createMock(BlueskyArchiveService::class);
		$this->control = $this->createMock(DidControlService::class);
		// Response::getHeaders() reads both from the container
		\OC::$server->register(IRequest::class, $this->createStub(IRequest::class));
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new \DateTime('@1760000000'));
		\OC::$server->register(ITimeFactory::class, $clock);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function controller(): AtprotoRecoveryController {
		$session = $this->createStub(IUserSession::class);
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($user);
		$config = $this->createStub(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$accounts = $this->createStub(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturn((new Person())->setUserId('alice'));

		return new AtprotoRecoveryController($this->createStub(IRequest::class), $session, $config, $accounts, $this->identities, $this->archive, $this->control, new NullLogger());
	}

	private static function identity(string $state = Identity::STATE_ACTIVE): Identity {
		return new Identity(1, 'https://social.test/@alice', 'did:plc:alice', 'alice.social.test', 'sealed', 'did:key:z', '', $state, '', 0);
	}

	public function testTheDownloadAsksForThePasswordAndIsRateLimited(): void {
		$method = new \ReflectionMethod(AtprotoRecoveryController::class, 'archive');

		$this->assertCount(1, $method->getAttributes(PasswordConfirmationRequired::class));
		$limit = $method->getAttributes(UserRateLimit::class)[0]->newInstance();
		$this->assertSame([3, 3600], [$limit->getLimit(), $limit->getPeriod()]);
	}

	public function testTheArchiveIsSentInPiecesAndRemovedAfter(): void {
		$path = (string)tempnam(sys_get_temp_dir(), 'bluesky-archive-test');
		file_put_contents($path, str_repeat('z', 3 * 1024 * 1024 + 5));
		$this->identities->method('forActor')->willReturn(self::identity());
		$this->archive->expects($this->once())->method('zip')->willReturn($path);
		$this->archive->method('filename')->willReturn('bluesky-alice.social.test-2026-10-14.zip');

		$response = $this->controller()->archive();

		$this->assertInstanceOf(StreamedPiecesResponse::class, $response);
		$this->assertSame('application/zip', $response->getHeaders()['Content-Type']);
		$this->assertSame('attachment; filename="bluesky-alice.social.test-2026-10-14.zip"', $response->getHeaders()['Content-Disposition']);
		$this->assertSame((string)(3 * 1024 * 1024 + 5), $response->getHeaders()['Content-Length']);
		$pieces = [];
		$output = $this->createMock(IOutput::class);
		$output->method('setOutput')->willReturnCallback(function (string $piece) use (&$pieces): void {
			$pieces[] = strlen($piece);
		});
		$level = ob_get_level();
		$response->callback($output);
		while (ob_get_level() < $level) {
			ob_start();
		}
		$this->assertSame([1024 * 1024, 1024 * 1024, 1024 * 1024, 5], $pieces);
		$this->assertFileDoesNotExist($path);
	}

	public function testThereIsNothingToDownloadWithoutAnAccountHostedHere(): void {
		$this->archive->expects($this->never())->method('zip');

		$this->identities->method('forActor')->willReturnOnConsecutiveCalls(null, self::identity(Identity::STATE_MOVED_AWAY));
		foreach ([$this->controller()->archive(), $this->controller()->archive()] as $response) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame('no-identity', $response->getData()['code']);
		}
	}

	/** Who controls the DID, as the directory has it; each failure with the code the page explains it by. */
	public function testWhoControlsTheDidIsAnsweredOrSaidWhyNot(): void {
		$this->identities->method('forActor')->willReturn(self::identity());
		$control = ['did' => 'did:plc:alice', 'state' => 'active', 'rotation_keys' => [['key' => 'did:key:zServer', 'role' => 'server']], 'last_operation' => null, 'undo' => null];
		$this->control->method('forIdentity')->willReturnOnConsecutiveCalls(
			$control,
			$this->throwException(new AtprotoException('PLC directory could not be reached')),
			$this->throwException(new \InvalidArgumentException('The PLC directory does not know did:plc:alice')),
		);

		$this->assertSame($control, $this->controller()->control()->getData());
		$unreachable = $this->controller()->control();
		$this->assertSame([Http::STATUS_SERVICE_UNAVAILABLE, 'directory-unreachable'], [$unreachable->getStatus(), $unreachable->getData()['code']]);
		$unknown = $this->controller()->control();
		$this->assertSame([Http::STATUS_NOT_FOUND, 'did-not-found'], [$unknown->getStatus(), $unknown->getData()['code']]);
	}

	public function testThereIsNoControlToShowWithoutAnAccountHostedHere(): void {
		$this->identities->method('forActor')->willReturn(null);
		$this->control->expects($this->never())->method('forIdentity');

		$this->assertSame('no-identity', $this->controller()->control()->getData()['code']);
	}
}
