<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Listeners;

use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Listeners\UserAccountListener;
use OCA\Social\Service\AccountService;
use OCP\Accounts\UserUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserAccountListenerTest extends TestCase {
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var LoggerInterface&MockObject */
	private $logger;
	private UserAccountListener $listener;

	protected function setUp(): void {
		$this->accountService = $this->createMock(AccountService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new UserAccountListener($this->accountService, $this->logger);
	}

	private function userUpdated(string $uid): UserUpdatedEvent {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return new UserUpdatedEvent($user, ['displayname' => 'New Name']);
	}

	public function testAccountUpdateRefreshesTheCachedLocalActor(): void {
		$this->accountService->expects($this->once())->method('cacheLocalActorByUsername')->with('alice');
		$this->logger->expects($this->never())->method('warning');

		$this->listener->handle($this->userUpdated('alice'));
	}

	public function testOtherEventsAreIgnored(): void {
		$this->accountService->expects($this->never())->method('cacheLocalActorByUsername');

		$this->listener->handle(new Event());
	}

	public function testRefreshFailuresAreLoggedNotThrown(): void {
		$exception = new AccountDoesNotExistException('no social account');
		$this->accountService->method('cacheLocalActorByUsername')->willThrowException($exception);
		$this->logger->expects($this->once())->method('warning')
			->with('issue while updating user account', ['exception' => $exception]);

		$this->listener->handle($this->userUpdated('bob'));
	}
}
