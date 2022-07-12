<?php
declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Tests\Service;

use OCA\Social\Entity\Account;
use OCA\Social\Entity\Status;
use OCA\Social\Service\AccountFinder;
use OCA\Social\Service\Feed\FeedManager;
use OCA\Social\Service\Feed\PostDeliveryService;
use OCP\DB\ORM\IEntityManager;
use OCP\IDBConnection;
use OCP\Server;
use phpseclib3\Exception\FileNotFoundException;
use PHPUnit\Framework\MockObject\MockClass;
use Test\TestCase;

class FeedManagerTest extends TestCase {

	private ?Account $account1 = null;
	private ?Account $account2 = null;
	private ?Account $account3 = null;
	private ?AccountFinder $accountFinder = null;
	private ?PostDeliveryService $postDeliveryService = null;
	/** @var MockClass&FeedManager */
	private $feedManager;
	private IEntityManager $em;

	public function setUp(): void {
		parent::setUp();

		$this->em = Server::get(IEntityManager::class);

		$this->account1 = Account::newLocal('user1', 'user1', 'User1');
		$this->account2 = Account::newLocal('user2', 'user2', 'User2');
		$this->account3 = Account::newLocal('user3', 'user3', 'User3');

		$this->em->persist($this->account1);
		$this->em->persist($this->account2);
		$this->em->persist($this->account3);
		$this->em->flush();

		$this->accountFinder = Server::get(AccountFinder::class);
		$this->feedManager = $this->createMock(FeedManager::class);
		$this->postDeliveryService = new PostDeliveryService($this->feedManager, $this->accountFinder);
	}

	public function tearDown(): void {
		Server::get(IDBConnection::class)->executeStatement('DELETE from **PREFIX**social_status');
		parent::tearDown();
	}

	public function testFollowAccont(): void {
		$feedManager = Server::get(FeedManager::class);

		for ($i = 0; $i < 100; $i++) {
			$status = new Status();
			$status->setAccount($this->account2);
			$status->setText('Hello world!');
			$this->em->persist($status);
			$this->em->flush();
			$feedManager->addToHome($this->account2->getId(), $status);

			$status1 = new Status();
			$status1->setAccount($this->account3);
			$status1->setText('Hello world!');
			$this->em->persist($status1);
			$this->em->flush();
			$feedManager->addToHome($this->account3->getId(), $status1);
		}

		$status->setLocal(true);
		$this->account1->follow($this->account2);
		$feedManager->mergeIntoHome($this->account2, $this->account1);

		$status->setLocal(true);
		$this->account1->follow($this->account3);
		$feedManager->mergeIntoHome($this->account3, $this->account1);
	}
}
