<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\KeyboardShortcutsController;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The switch for the web app's keyboard shortcuts: stored per account, and a
 * request that is not a yes or a no leaves it as it was.
 */
#[AllowMockObjectsWithoutExpectations]
class KeyboardShortcutsControllerTest extends TestCase {
	private IRequest|Stub $request;
	private IUserSession|Stub $userSession;
	private AccountService|Stub $accountService;
	private ConfigService|Stub $configService;
	private bool $csrf = true;
	/** @var array<string, array<string, string>> user id => key => value as stored */
	private array $stored = [];

	protected function setUp(): void {
		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')->willReturn('');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getRequestUri')->willReturn('/index.php/apps/social/api/v1/social/keyboard_shortcuts');

		$this->userSession = $this->createStub(IUserSession::class);
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->accountService = $this->createStub(AccountService::class);
		$this->accountService->method('getActorFromUserId')->willReturnCallback(function (): Person {
			$viewer = new Person();
			$viewer->setId('https://cloud.example/users/alice');
			$viewer->setUserId('alice');

			return $viewer;
		});

		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('setValueForUser')->willReturnCallback(function ($uid, $key, $value): void {
			$this->stored[$uid][$key] = $value;
		});

		\OC::$server->register(IRequest::class, $this->request);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testTurningThemOffAndOnIsStoredForTheViewer(): void {
		$response = $this->controller()->update('false');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['enabled' => false], $response->getData());
		$this->assertSame('0', $this->stored['alice'][ConfigService::USER_KEYBOARD_SHORTCUTS]);

		$this->assertSame(['enabled' => true], $this->controller()->update(true)->getData());
		$this->assertSame('1', $this->stored['alice'][ConfigService::USER_KEYBOARD_SHORTCUTS]);
	}

	public function testWhatIsNotABooleanIsRefused(): void {
		foreach ([null, '', 'maybe', ['x']] as $raw) {
			$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller()->update($raw)->getStatus(), var_export($raw, true));
		}
		$this->assertSame([], $this->stored, 'a refused request must not flip the switch');
	}

	public function testTheSwitchNeedsAViewer(): void {
		$this->csrf = false;

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->update('false')->getStatus());
		$this->assertSame([], $this->stored);
	}

	private function controller(): KeyboardShortcutsController {
		return new KeyboardShortcutsController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->createStub(ClientService::class),
			$this->configService,
		);
	}
}
