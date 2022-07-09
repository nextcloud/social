<?php
declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Tests\Service\Feed;

use OCA\Social\Entity\Account;
use OCA\Social\Entity\Status;
use OCA\Social\Service\AccountFinder;
use OCA\Social\Service\Feed\PostDeliveryService;
use OCA\Social\Service\Feed\FeedManager;
use OCP\DB\ORM\IEntityManager;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockClass;
use Test\TestCase;

class PostDeliveryServiceTest extends TestCase {
	private ?Account $account1 = null;
	private ?Account $account2 = null;
	private ?Account $account3 = null;
	private ?AccountFinder $accountFinder = null;
	private ?PostDeliveryService $postDeliveryService = null;
	/** @var MockClass&FeedManager */
	private $feedManager;

	public function setUp(): void {
		parent::setUp();

		$em = Server::get(IEntityManager::class);

		$this->account1 = Account::newLocal('user1', 'user1', 'User1');
		$this->account2 = Account::newLocal('user2', 'user2', 'User2');
		$this->account3 = Account::newLocal('user3', 'user3', 'User3');
		$this->account2->follow($this->account1);

		$em->persist($this->account1);
		$em->persist($this->account2);
		$em->persist($this->account3);
		$em->flush();

		$this->accountFinder = Server::get(AccountFinder::class);
		$this->feedManager = $this->createMock(FeedManager::class);
		$this->postDeliveryService = new PostDeliveryService($this->feedManager, $this->accountFinder);
	}

	public function tearDown(): void {
		$em = Server::get(IEntityManager::class);
		$em->remove($this->account1);
		$em->remove($this->account2);
		$em->flush();

		parent::tearDown();
	}

	public function testCreateBasicStatus(): void {
		$status = new Status();
		$status->setAccount($this->account1);
		$status->setText('Hello world!');
		$status->setLocal(true);
		$this->feedManager->expects($this->exactly(2))
			->method('addToHome')
			->withConsecutive(
				[$this->account1->getId(), $status], // self
				[$this->account2->getId(), $status] // follower
			);
		$this->postDeliveryService->run($status);
	}

	public function testCreateBasicStatusWithLocalMention(): void {
		$status = new Status();
		$status->setAccount($this->account1);
		$status->setText('Hello world @user3!');
		$status->setLocal(true);
		\OCP
		$this->feedManager->expects($this->exactly(2))
			->method('addToHome')
			->withConsecutive(
				[$this->account1->getId(), $status], // self
				[$this->account2->getId(), $status] // follower
				[$this->account3->getId(), $status] // follower
			);
		$this->postDeliveryService->run($status);
	}
}
