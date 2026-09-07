<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use DateTime;
use OCA\Social\Service\UpdateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IAction;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UpdateServiceTest extends TestCase {
	private IUserManager|MockObject $userManager;
	private IGroupManager|MockObject $groupManager;
	private INotificationManager|MockObject $notificationManager;
	private DateTime $now;
	private UpdateService $service;

	protected function setUp(): void {
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->now = new DateTime('2026-09-07 10:00:00');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn($this->now);

		$this->service = new UpdateService($this->userManager, $this->groupManager, $time, $this->notificationManager);
	}

	private function user(string $uid): IUser|MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}

	/** A notification stub whose fluent setters return itself and that records what was set. */
	private function notification(array &$calls): INotification|MockObject {
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setDateTime', 'setUser', 'setObject', 'setSubject', 'addAction'] as $method) {
			$notification->method($method)->willReturnCallback(function (...$args) use ($notification, $method, &$calls) {
				$calls[$method][] = $args;

				return $notification;
			});
		}

		return $notification;
	}

	public function testCreateNotificationFillsEveryField(): void {
		$calls = [];
		$this->notificationManager->method('createNotification')->willReturn($this->notification($calls));

		$this->service->createNotification('alice', 'update_alpha3', ['k' => 'v']);

		$this->assertSame([['social']], $calls['setApp']);
		$this->assertSame([[$this->now]], $calls['setDateTime']);
		$this->assertSame([['alice']], $calls['setUser']);
		$this->assertSame([['update', 'update_alpha3']], $calls['setObject']);
		$this->assertSame([['update_alpha3', ['k' => 'v']]], $calls['setSubject']);
	}

	public function testGenerateNotificationsForEveryUser(): void {
		$this->userManager->method('search')->with('')->willReturn([$this->user('alice'), $this->user('bob')]);
		$this->groupManager->expects($this->never())->method('isAdmin');
		$calls = [];
		$this->notificationManager->method('createNotification')
			->willReturnCallback(function () use (&$calls) {
				return $this->notification($calls);
			});

		$notifications = $this->service->generateNotifications(false, 'subject', []);

		$this->assertCount(2, $notifications);
		$this->assertSame([['alice'], ['bob']], $calls['setUser']);
	}

	public function testGenerateNotificationsForAdminsOnlyFiltersByGroupManager(): void {
		$this->userManager->method('search')->willReturn([$this->user('alice'), $this->user('bob'), $this->user('carol')]);
		$this->groupManager->method('isAdmin')->willReturnCallback(fn (string $uid) => $uid !== 'bob');
		$calls = [];
		$this->notificationManager->method('createNotification')
			->willReturnCallback(function () use (&$calls) {
				return $this->notification($calls);
			});

		$notifications = $this->service->generateNotifications(true, 'subject', []);

		$this->assertCount(2, $notifications);
		$this->assertSame([['alice'], ['carol']], $calls['setUser']);
	}

	public function testGenerateNotificationsWithoutUsersIsEmpty(): void {
		$this->userManager->method('search')->willReturn([]);
		$this->notificationManager->expects($this->never())->method('createNotification');

		$this->assertSame([], $this->service->generateNotifications(true, 'subject', []));
	}

	public function testCheckUpdateStatusNotifiesAdminsWithAHelpLink(): void {
		$this->userManager->method('search')->willReturn([$this->user('admin'), $this->user('bob')]);
		$this->groupManager->method('isAdmin')->willReturnCallback(fn (string $uid) => $uid === 'admin');

		$action = $this->createMock(IAction::class);
		$action->expects($this->once())->method('setLabel')->with('help')->willReturnSelf();
		$action->expects($this->once())
			->method('setLink')
			->with('https://help.nextcloud.com/t/social-alpha3-how-to-upgrade/85535', 'WEB')
			->willReturnSelf();

		$calls = [];
		$notification = $this->notification($calls);
		$notification->method('createAction')->willReturn($action);
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())
			->method('notify')
			->with($this->identicalTo($notification));

		$this->service->checkUpdateStatus();

		$this->assertSame([['admin']], $calls['setUser']);
		$this->assertSame([['update_alpha3', []]], $calls['setSubject']);
		$this->assertSame([[$action]], $calls['addAction']);
	}
}
