<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use Closure;
use OCA\Social\Atproto\Identity\DidControlService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Service\BlueskyArchiveService;
use OCA\Social\Atproto\Service\RecoveryToolPage;
use OCA\Social\Controller\AtprotoRecoveryController;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Response\StreamedWriterResponse;
use OCA\Social\Service\AccountService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\UserMigration\UserMigrationException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The download of a person's Bluesky account: only their own, only after
 * the password, a few an hour, sent while it is written.
 */
#[AllowMockObjectsWithoutExpectations]
class AtprotoRecoveryControllerTest extends TestCase {
	private IdentityService&MockObject $identities;
	private BlueskyArchiveService&MockObject $archive;
	private DidControlService&MockObject $control;
	private RecoveryToolPage&MockObject $recoveryTool;

	protected function setUp(): void {
		$this->identities = $this->createMock(IdentityService::class);
		$this->archive = $this->createMock(BlueskyArchiveService::class);
		$this->control = $this->createMock(DidControlService::class);
		$this->recoveryTool = $this->createMock(RecoveryToolPage::class);
		// Response::getHeaders() reads both from the container
		\OC::$server->register(IRequest::class, $this->createStub(IRequest::class));
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new \DateTime('@1760000000'));
		\OC::$server->register(ITimeFactory::class, $clock);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private ?AccountService $accounts = null;

	private function controller(?LoggerInterface $logger = null): AtprotoRecoveryController {
		$session = $this->createStub(IUserSession::class);
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($user);
		$config = $this->createStub(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$accounts = $this->accounts;
		if ($accounts === null) {
			$accounts = $this->createStub(AccountService::class);
			$accounts->method('getActorFromUserId')->willReturn((new Person())->setUserId('alice'));
		}

		return new AtprotoRecoveryController($this->createStub(IRequest::class), $session, $config, $accounts, $this->identities, $this->archive, $this->control, $this->recoveryTool, $logger ?? new NullLogger());
	}

	/** Reading who controls an account never makes the account it reads. */
	public function testAViewerWithoutAnAccountIsToldWhereToSetItUp(): void {
		$asked = [];
		$this->accounts = $this->createMock(AccountService::class);
		$this->accounts->method('getActorFromUserId')->willReturnCallback(
			function (string $userId, bool $create = false) use (&$asked): Person {
				$asked[] = $create;

				throw new ActorDoesNotExistException('Actor not found for user: ' . $userId);
			}
		);
		$this->identities->expects($this->never())->method('create');

		foreach (['control' => fn () => $this->controller()->control(), 'archive' => fn () => $this->controller()->archive()] as $route => $call) {
			$response = $call();
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), $route);
			$this->assertSame('account-not-set-up', $response->getData()['code'], $route);
		}
		$this->assertNotContains(true, $asked, 'a route asked for the account to be made');
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

	/**
	 * Runs the response's body, as the framework does after the headers,
	 * and gives what it wrote along with $events.
	 *
	 * @param list<string> $events
	 */
	private function send(StreamedWriterResponse $response, array &$events): void {
		$output = $this->createMock(IOutput::class);
		$output->method('setOutput')->willReturnCallback(static function (string $piece) use (&$events): void {
			$events[] = 'sent ' . $piece;
		});
		$level = ob_get_level();
		$response->callback($output);
		while (ob_get_level() < $level) {
			ob_start();
		}
	}

	public function testTheArchiveIsSentWhileItIsWritten(): void {
		$this->identities->method('forActor')->willReturn(self::identity());
		$events = [];
		$this->archive->expects($this->once())->method('zip')->willReturnCallback(static function (Identity $identity, Closure $sink) use (&$events): void {
			$sink('PK first files');
			$events[] = 'reading the blobs';
			$sink('the blobs');
		});
		$this->archive->method('filename')->willReturn('bluesky-alice.social.test-2026-10-14.zip');

		$response = $this->controller()->archive();

		$this->assertInstanceOf(StreamedWriterResponse::class, $response);
		$this->assertSame('application/zip', $response->getHeaders()['Content-Type']);
		$this->assertSame('attachment; filename="bluesky-alice.social.test-2026-10-14.zip"', $response->getHeaders()['Content-Disposition']);
		$this->assertArrayNotHasKey('Content-Length', $response->getHeaders());
		$this->assertSame([], $events, 'nothing is read before the response is sent');
		$this->send($response, $events);
		$this->assertSame(['sent PK first files', 'reading the blobs', 'sent the blobs'], $events);
	}

	/** Once the first bytes are out the status cannot change: the failure is logged and the archive left unfinished. */
	public function testAnArchiveThatFailsHalfwayIsLoggedAndCutShort(): void {
		$this->identities->method('forActor')->willReturn(self::identity());
		$this->archive->method('zip')->willReturnCallback(static function (Identity $identity, Closure $sink): void {
			$sink('PK first files');
			throw new UserMigrationException('could not read a blob');
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Bluesky export failed');
		$events = [];

		$this->send($this->controller($logger)->archive(), $events);

		$this->assertSame(['sent PK first files'], $events);
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

	/** The tool is one page: its inline script allowed by hash, any HTTPS server reachable, and a file to keep from the download. */
	public function testTheRecoveryToolIsServedAndDownloadedAsOnePage(): void {
		$this->recoveryTool->method('isBuilt')->willReturn(true);
		$this->recoveryTool->method('render')->willReturn('<!DOCTYPE html><title>tool</title>');
		$this->recoveryTool->method('scriptSource')->willReturn("'sha256-abc='");

		$served = $this->controller()->recoveryTool();
		$this->assertSame('<!DOCTYPE html><title>tool</title>', $served->render());
		$this->assertSame('text/html; charset=utf-8', $served->getHeaders()['Content-Type']);
		$policy = $served->getContentSecurityPolicy()->buildPolicy();
		$this->assertStringContainsString("'sha256-abc='", $policy);
		$this->assertMatchesRegularExpression('/connect-src [^;]*https:/', $policy);
		$this->assertStringNotContainsString('attachment', $served->getHeaders()['Content-Disposition'] ?? '');

		$downloaded = $this->controller()->downloadRecoveryTool();
		$this->assertSame('attachment; filename="bluesky-recovery-tool.html"', $downloaded->getHeaders()['Content-Disposition']);
	}

	public function testWithoutABuildTheToolSaysSo(): void {
		$this->recoveryTool->method('isBuilt')->willReturn(false);

		$response = $this->controller()->recoveryTool();
		$this->assertSame([Http::STATUS_SERVICE_UNAVAILABLE, 'tool-missing'], [$response->getStatus(), $response->getData()['code']]);
	}
}
