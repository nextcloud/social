<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Social\Service;

use Exception;
use OC\User\NoUserException;
use OCA\Social\AppInfo\Application;
use OCP\ILogger;
use OCP\IUserManager;

class MiscService {


	/** @var ILogger */
	private $logger;

	/** @var IUserManager */
	private $userManager;


	/**
	 * MiscService constructor.
	 *
	 * @param ILogger $logger
	 * @param IUserManager $userManager
	 */
	public function __construct(ILogger $logger, IUserManager $userManager) {
		$this->logger = $logger;
		$this->userManager = $userManager;
	}


	/**
	 * @param $message
	 * @param int $level
	 */
	public function log($message, $level = 2) {
		$data = array(
			'app'   => Application::APP_NAME,
			'level' => $level
		);

		$this->logger->log($level, $message, $data);
	}


	/**
	 * @param array $keys
	 * @param array $arr
	 *
	 * @throws Exception
	 */
	public function mustContains(array $keys, array $arr) {
		foreach ($keys as $key) {
			if (!array_key_exists($key, $arr)) {
				throw new Exception('missing elements');
			}
		}
	}

	public static function noEndSlash($path) {
		if (substr($path, -1) === '/') {
			$path = substr($path, 0, -1);
		}

		return $path;
	}


	/**
	 * @param string $path
	 */
	public function formatPath(string &$path) {
		if ($path === '') {
			return;
		}

		if (substr($path, 0, 1) === '/') {
			$path = substr($path, 1);
		}

		if (substr($path, -1) !== '/') {
			$path .= '/';
		}
	}


	/**
	 * @param string $userId
	 *
	 * @throws NoUserException
	 */
	public function confirmUserId(string &$userId) {
		$user = $this->userManager->get($userId);

		return;
		if ($user === null) {
			throw new NoUserException('user does not exist');
		}

		$userId = $user->getUID();
	}

}

