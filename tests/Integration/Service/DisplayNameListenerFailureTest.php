<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserManager;
use OCP\Server;
use OCP\User\Events\UserChangedEvent;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A rename reaches the actor even when a listener for the change throws.
 *
 * Nextcloud copies a new display name into the profile in its own listener
 * for `UserChangedEvent`, which runs after the apps' listeners and not at all
 * once one of them throws; the user backend has stored the name by then.
 */
class DisplayNameListenerFailureTest extends TestCase {
	private IUserManager $userManager;
	private IEventDispatcher $dispatcher;
	private string $userId = '';
	/** @var callable|null */
	private $failing = null;

	protected function setUp(): void {
		parent::setUp();
		$this->userManager = Server::get(IUserManager::class);
		$this->dispatcher = Server::get(IEventDispatcher::class);
	}

	protected function tearDown(): void {
		if ($this->failing !== null) {
			$this->dispatcher->removeListener(UserChangedEvent::class, $this->failing);
		}
		if ($this->userId !== '') {
			$this->userManager->get($this->userId)?->delete();
			Server::get(ActorsRequest::class)->delete($this->userId);
		}
		parent::tearDown();
	}

	public function testTheActorTakesTheNewNameWhenAnEarlierListenerThrows(): void {
		$this->userId = 'renamed' . bin2hex(random_bytes(4));
		$this->userManager->createUser($this->userId, bin2hex(random_bytes(16)) . 'Aa1!');
		$accounts = Server::get(AccountService::class);
		$actor = $accounts->getActorFromUserId($this->userId, true);
		$this->assertSame($this->userId, Server::get(CacheActorService::class)->getFromId($actor->getId())->getName());

		$this->failing = static function (UserChangedEvent $event): void {
			if ($event->getFeature() === 'displayName') {
				throw new RuntimeException('a listener failed');
			}
		};
		$this->dispatcher->addListener(UserChangedEvent::class, $this->failing, 100);

		$accounts->setDisplayName($this->userId, 'Example Inc');

		$this->assertSame('Example Inc', $this->userManager->get($this->userId)?->getDisplayName(), 'the backend stored the name');
		$this->assertSame('Example Inc', Server::get(CacheActorService::class)->getFromId($actor->getId())->getName(), 'the actor carries it');
		$this->assertSame('Example Inc', Server::get(CacheActorsRequest::class)->getFromLocalAccount($actor->getPreferredUsername())->getName(), 'and so does its cached copy');
	}
}
