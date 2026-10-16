<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\ModerationListController;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ModerationList\ModerationListService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The shared lists a person mutes or blocks with, for somebody without an account here. */
#[AllowMockObjectsWithoutExpectations]
class ModerationListControllerTest extends TestCase {
	protected function setUp(): void {
		\OC::$server->register(IRequest::class, $this->createStub(IRequest::class));
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testAReaderWithoutAnAccountIsToldWhereToSetItUp(): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$accounts = $this->createStub(AccountService::class);
		$accounts->method('getActorFromUserId')->willThrowException(new ActorDoesNotExistException('Actor not found for user: alice'));
		$lists = $this->createMock(ModerationListService::class);
		$lists->expects($this->never())->method('subscribe');
		$lists->expects($this->never())->method('unsubscribe');
		$controller = new ModerationListController($this->createStub(IRequest::class), $session, $accounts, $lists, new NullLogger());

		foreach ([
			'subscribe' => fn () => $controller->subscribe('https://bsky.app/profile/x/lists/y'),
			'unsubscribe' => fn () => $controller->unsubscribe('at://did:plc:x/app.bsky.graph.list/y'),
		] as $route => $call) {
			$response = $call();
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), $route);
			$this->assertSame('account-not-set-up', $response->getData()['code'], $route);
			$this->assertSame('https://cloud.example.org/social.Navigation.navigate', $response->getData()['setup_url'], $route);
		}
	}
}
