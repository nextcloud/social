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


namespace OCA\Social\Cron;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\QueryException;

/**
 * Class Queue
 *
 * @package OCA\Social\Cron
 */
class Chunk extends TimedJob {
	private ConfigService $configService;

	public function __construct(ITimeFactory $time, ConfigService $configService) {
		parent::__construct($time);
		$this->setInterval(12 * 3600); // 12 hours
		$this->configService = $configService;
	}


	/**
	 * @param mixed $argument
	 *
	 * @throws QueryException
	 */
	protected function run($argument) {
		$size = (int)$this->configService->getAppValue(ConfigService::DATABASE_CHUNK_SIZE);
		$this->morphChunks($size);
	}


	/**
	 * @param int $size
	 */
	private function morphChunks(int $size): void {
	}
}
