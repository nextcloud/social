<?php
/**
 * @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
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


	/** @var IUserManager */
	private $userManager;

	/** @var IGroupManager */
	private $groupManager;

	/** @var ITimeFactory */
	private $time;

	/** @var INotificationManager */
	private $notificationManager;


	/** @var string */
	private $updateId = 'alpha3';


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

