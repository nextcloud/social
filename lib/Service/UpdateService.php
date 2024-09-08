<?php
/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;

/**
 * Class UpdateService
 *
 * @package OCA\Social\Service
 */
class UpdateService {
	private IUserManager $userManager;

	private IGroupManager $groupManager;

	private ITimeFactory $time;

	private INotificationManager $notificationManager;


	private string $updateId = 'alpha3';


	/**
	 * UpdateService constructor.
	 *
	 * @param IUserManager $userManager
	 * @param IGroupManager $groupManager
	 * @param ITimeFactory $time
	 * @param INotificationManager $notificationManager
	 */
	public function __construct(
		IUserManager $userManager, IGroupManager $groupManager, ITimeFactory $time,
		INotificationManager $notificationManager
	) {
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->time = $time;
		$this->notificationManager = $notificationManager;
	}


	public function checkUpdateStatus() {
		$notifications = $this->generateNotifications(true, 'update_alpha3', []);

		foreach ($notifications as $notif) {
			$help = $notif->createAction();
			$help->setLabel('help')
				 ->setLink('https://help.nextcloud.com/t/social-alpha3-how-to-upgrade/85535', 'WEB');

			$notif->addAction($help);
			$this->notificationManager->notify($notif);
		}
	}


	/**
	 * @param bool $adminOnly
	 * @param string $subject
	 * @param array $data
	 *
	 * @return INotification[]
	 */
	public function generateNotifications(bool $adminOnly, string $subject, array $data): array {
		$notifications = [];
		$users = $this->userManager->search('');

		if ($adminOnly) {
			$admin = [];
			foreach ($users as $user) {
				if ($this->groupManager->isAdmin($user->getUID())) {
					$admin[] = $user;
				}
			}
			$users = $admin;
		}

		foreach ($users as $user) {
			$notifications[] = $this->createNotification($user->getUID(), $subject, $data);
		}

		return $notifications;
	}


	/**
	 * @param string $userId
	 * @param string $subject
	 * @param array $data
	 *
	 * @return INotification
	 */
	public function createNotification(string $userId, string $subject, array $data): INotification {
		$now = $this->time->getDateTime();
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('social')
					 ->setDateTime($now)
					 ->setUser($userId)
					 ->setObject('update', 'update_' . $this->updateId)
					 ->setSubject($subject, $data);

		return $notification;
	}
}
