<?php
declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Tests\Service;

use OCA\Social\Entity\Account;
use OCA\Social\Entity\Follow;
use OCA\Social\Service\AccountFinder;
use OCP\DB\ORM\IEntityManager;
use OCP\Server;
use Test\TestCase;

/**
 * @group DB
 */
class AccountFinderTest extends TestCase {
	private ?Account $account1 = null;
	private ?Account $account2 = null;
	private ?AccountFinder $accountFinder = null;

	public function setUp(): void {
		parent::setUp();

		$em = Server::get(IEntityManager::class);

		$this->account1 = Account::newLocal('user1', 'user1', 'User1');
		$this->account2 = Account::newLocal('user2', 'user2', 'User2');
		$this->account2->follow($this->account1);

		$em->persist($this->account1);
		$em->persist($this->account2);
		$em->flush();

		$this->accountFinder = Server::get(AccountFinder::class);
	}

	public function tearDown(): void {
		$em = Server::get(IEntityManager::class);
		$em->remove($this->account1);
		$em->remove($this->account2);
		$em->flush();

		parent::tearDown();
	}

	public function testGetLocalFollower(): void {
		$accounts = $this->accountFinder->getLocalFollowersOf($this->account1);
		$this->assertSame(count($accounts), 1);
		$this->assertSame($accounts[0]->getAccount()->getId(), $this->account2->getId());
	}

	public function testGetRepresentive(): void {
		$account = $this->accountFinder->getRepresentative();
		$account1 = $this->accountFinder->getRepresentative();

		// Caching works
		$this->assertSame($account, $account1);
	}
}
