<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ReportsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Flag;
use OCA\Social\Model\Report;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ReportService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ReportServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';
	private const BOB = 'https://cloud.example/apps/social/@bob';
	private const REMOTE_ACTOR = 'https://mastodon.social/actor';

	private ReportsRequest|MockObject $reportsRequest;
	private CacheActorService|MockObject $cacheActorService;
	private IUserManager|MockObject $userManager;
	private IGroupManager|MockObject $groupManager;
	private INotificationManager|MockObject $notificationManager;
	private ReportService $service;

	protected function setUp(): void {
		$this->reportsRequest = $this->createMock(ReportsRequest::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);

		$this->service = new ReportService(
			$this->reportsRequest,
			$this->cacheActorService,
			$this->userManager,
			$this->groupManager,
			$this->notificationManager,
			new NullLogger()
		);
	}

	private function person(string $id, bool $local = true): Person {
		$person = new Person();
		$person->setId($id);
		$person->setLocal($local);

		return $person;
	}

	private function user(string $uid): IUser|MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}

	/** admin + regular user; captures the subjects of the notifications sent */
	private function withAdmin(): \Closure {
		$this->userManager->method('search')->with('')
			->willReturn([$this->user('admin'), $this->user('john')]);
		$this->groupManager->method('isAdmin')
			->willReturnCallback(fn (string $uid): bool => $uid === 'admin');

		$subjects = [];
		$this->notificationManager->method('createNotification')
			->willReturnCallback(function () use (&$subjects): INotification {
				$notification = $this->createMock(INotification::class);
				foreach (['setApp', 'setDateTime', 'setUser', 'setObject'] as $method) {
					$notification->method($method)->willReturnSelf();
				}
				$notification->method('setSubject')
					->willReturnCallback(function (string $subject, array $params) use (&$subjects, $notification): INotification {
						$subjects[] = [$subject, $params];

						return $notification;
					});

				return $notification;
			});

		return function () use (&$subjects): array {
			return $subjects;
		};
	}

	public function testReportFromLocalStoresTheReportAndNotifiesOnlyAdmins(): void {
		$subjects = $this->withAdmin();

		$saved = null;
		$this->reportsRequest->expects($this->once())->method('save')
			->willReturnCallback(function (Report $report) use (&$saved): int {
				$saved = $report;

				return 7;
			});
		$this->notificationManager->expects($this->once())->method('notify');

		$report = $this->service->reportFromLocal(
			$this->person(self::ALICE), $this->person(self::BOB), ['12', ''], 'spam bot', 'spam'
		);

		$this->assertSame($saved, $report);
		$this->assertSame(self::ALICE, $report->getActorId());
		$this->assertSame(self::BOB, $report->getAccountId());
		$this->assertSame(['12'], $report->getStatusIds(), 'empty status ids are dropped');
		$this->assertSame('spam bot', $report->getComment());
		$this->assertSame('spam', $report->getCategory());
		$this->assertTrue($report->isLocal());
		$this->assertFalse($report->isResolved());

		$this->assertSame([['report_new', ['reporter' => self::ALICE, 'account' => self::BOB, 'local' => true]]], $subjects());
	}

	public function testAnUnknownCategoryFallsBackToOther(): void {
		$this->withAdmin();
		$this->reportsRequest->method('save')->willReturn(1);

		$report = $this->service->reportFromLocal(
			$this->person(self::ALICE), $this->person(self::BOB), [], '', 'weird-category'
		);

		$this->assertSame(Report::CATEGORY_OTHER, $report->getCategory());
	}

	public function testReportFromFlagSplitsTheLocalAccountFromTheStatuses(): void {
		$this->withAdmin();
		$this->cacheActorService->method('getFromId')
			->willReturnCallback(function (string $id): Person {
				if ($id === self::ALICE) {
					return $this->person(self::ALICE);
				}
				throw new CacheActorDoesNotExistException();
			});
		$this->reportsRequest->expects($this->once())->method('save')->willReturn(3);

		$flag = new Flag();
		$flag->import([
			'type' => 'Flag',
			'actor' => self::REMOTE_ACTOR,
			'content' => 'reported from remote',
			'object' => [self::ALICE . '/status/1', self::ALICE, self::ALICE . '/status/2'],
		]);

		$report = $this->service->reportFromFlag($flag);

		$this->assertSame(self::REMOTE_ACTOR, $report->getActorId());
		$this->assertSame(self::ALICE, $report->getAccountId(), 'the resolvable local account is the target');
		$this->assertSame(
			[self::ALICE . '/status/1', self::ALICE . '/status/2'],
			$report->getStatusIds(),
			'everything else is treated as a reported status'
		);
		$this->assertSame('reported from remote', $report->getComment());
		$this->assertFalse($report->isLocal());
	}

	public function testReportFromFlagKeepsTheFirstIdWhenNothingResolves(): void {
		$this->withAdmin();
		$this->cacheActorService->method('getFromId')
			->willThrowException(new CacheActorDoesNotExistException());
		$this->reportsRequest->method('save')->willReturn(4);

		$flag = new Flag();
		$flag->import([
			'type' => 'Flag',
			'actor' => self::REMOTE_ACTOR,
			'object' => ['https://gone.example/@x', 'https://gone.example/@x/1'],
		]);

		$report = $this->service->reportFromFlag($flag);

		$this->assertSame('https://gone.example/@x', $report->getAccountId());
		$this->assertSame(['https://gone.example/@x/1'], $report->getStatusIds());
	}

	public function testGetReportsResolvesTargetAccountsAndSurvivesFailures(): void {
		$known = new Report();
		$known->setAccountId(self::BOB);
		$gone = new Report();
		$gone->setAccountId('https://gone.example/@x');
		$this->reportsRequest->method('getAll')->willReturn([$known, $gone]);

		// one query for the accounts of the whole page, and no federated
		// request on a miss: a page of reports must not be able to hang on
		// someone else's instance
		$this->cacheActorService->expects($this->once())
			->method('getCachedFromIds')
			->with([self::BOB, 'https://gone.example/@x'])
			->willReturn([self::BOB => $this->person(self::BOB)]);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$reports = $this->service->getReports();

		$this->assertCount(2, $reports);
		$this->assertNotNull($reports[0]->getTargetAccount());
		$this->assertNull($reports[1]->getTargetAccount());
	}

	public function testSetResolvedOnAnUnknownReportThrows(): void {
		$this->reportsRequest->method('getById')->willThrowException(new ReportNotFoundException());
		$this->reportsRequest->expects($this->never())->method('setResolved');

		$this->expectException(ReportNotFoundException::class);

		$this->service->setResolved(9, true);
	}

	public function testABrokenNotificationDoesNotLoseTheReport(): void {
		$this->userManager->method('search')->willThrowException(new \RuntimeException('directory down'));
		$this->reportsRequest->expects($this->once())->method('save')->willReturn(5);

		$report = $this->service->reportFromLocal(
			$this->person(self::ALICE), $this->person(self::BOB), [], '', 'other'
		);

		$this->assertSame(self::BOB, $report->getAccountId());
	}
}
