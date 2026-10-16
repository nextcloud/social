<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\RepublishAvatars;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The followers' servers are told the new avatar address of the accounts
 * whose old one named a login other than their username, a page a run.
 */
class RepublishAvatarsTest extends TestCase {
	private function account(string $username, string $userId, int $deleted = 0): Person {
		$actor = new Person();
		$actor->setId('https://cloud.example/users/' . $username)
			->setPreferredUsername($username)
			->setUserId($userId)
			->setLocal(true);
		if ($deleted > 0) {
			$actor->setDeleted($deleted);
		}

		return $actor;
	}

	/**
	 * @param Person[] $page
	 */
	private function runJob(array $page, AccountService $accounts, IJobList $jobList, string $after = ''): void {
		$actorsRequest = $this->createMock(ActorsRequest::class);
		$actorsRequest->expects($this->once())->method('getPage')->with(RepublishAvatars::PAGE, $after)->willReturn($page);

		$job = new RepublishAvatars($this->createStub(ITimeFactory::class), $actorsRequest, $accounts, $jobList, new NullLogger());
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, ['after' => $after]);
	}

	public function testOnlyAccountsNamedApartFromTheirLoginAreTold(): void {
		$told = [];
		$accounts = $this->createStub(AccountService::class);
		$accounts->method('republishActor')->willReturnCallback(function (Person $actor) use (&$told): void {
			$told[] = $actor->getPreferredUsername();
		});
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('add');

		$this->runJob([
			$this->account('sunflower', 'jane.doe'),
			$this->account('bob', 'bob'),
			$this->account('carol', 'Carol'),
			$this->account('crew', 'team/crew'),
			$this->account('clips', 'channel/clips'),
			$this->account('gone', 'gone.user', 1700000000),
			$this->account('daisy', 'dan'),
		], $accounts, $jobList);

		$this->assertSame(['sunflower', 'daisy'], $told);
	}

	public function testAFullPageQueuesTheNextOneFromWhereItGotTo(): void {
		$page = [];
		for ($i = 0; $i < RepublishAvatars::PAGE; $i++) {
			$page[] = $this->account('user' . $i, 'user' . $i);
		}
		$last = ActorsRequest::rowPrimOf($page[RepublishAvatars::PAGE - 1]);
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('add')->with(RepublishAvatars::class, ['after' => $last]);

		$this->runJob($page, $this->createStub(AccountService::class), $jobList, 'prim-before');
	}

	public function testOneAccountThatCannotBeToldDoesNotStopTheRest(): void {
		$told = [];
		$accounts = $this->createStub(AccountService::class);
		$accounts->method('republishActor')->willReturnCallback(function (Person $actor) use (&$told): void {
			if ($actor->getPreferredUsername() === 'first') {
				throw new RuntimeException('no route to host');
			}
			$told[] = $actor->getPreferredUsername();
		});

		$this->runJob([$this->account('first', 'one'), $this->account('second', 'two')], $accounts, $this->createStub(IJobList::class));

		$this->assertSame(['second'], $told);
	}
}
