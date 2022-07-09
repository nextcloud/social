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

	public function setUp(): void {
		parent::setUp();

		$em = Server::get(IEntityManager::class);

		$this->account1 = Account::newLocal('user1', 'user1', 'User1');
		$this->account2 = Account::newLocal('user2', 'user2', 'User2');
		$this->account2->follow($this->account1);

		$em->persist($this->account1);
		$em->persist($this->account2);
		$em->flush();
	}

	public function tearDown(): void {
		$em = Server::get(IEntityManager::class);
		$em->remove($this->account1);
		$em->remove($this->account2);
		$em->flush();

		parent::tearDown();
	}

	public function testGetLocalFollower(): void {
		$accountFinder = Server::get(AccountFinder::class);
		$accounts = $accountFinder->getLocalFollowersOf($this->account1);
		var_dump(count($accounts));
	}
}
