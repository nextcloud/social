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

use OCA\Social\AppInfo\Application;
use OCP\IUserManager;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Class MiscService
 *
 * @package OCA\Social\Service
 */
class MiscService {
	private LoggerInterface $logger;
	private IUserManager $userManager;


	public function __construct(LoggerInterface $logger, IUserManager $userManager) {
		$this->logger = $logger;
		$this->userManager = $userManager;
	}


	/**
	 * @param $message
	 * @param int $level
	 */
	public function log(string $message, $level = 2) {
		$data = array(
			'app' => Application::APP_ID,
			'level' => $level
		);

		$this->logger->log($level, $message, $data);
	}


	/**
	 * @return int
	 */
	public function getNcVersion(): int {
		$ver = Util::getVersion();

		return $ver[0];
	}
}
