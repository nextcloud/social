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


use OC\BackgroundJob\TimedJob;
use OCA\Social\AppInfo\Application;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\AppFramework\QueryException;


/**
 * Class Queue
 *
 * @package OCA\Social\Cron
 */
class Chunk extends TimedJob {

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * Cache constructor.
	 */
	public function __construct() {
		$this->setInterval(12 * 3600); // 12 heures
	}


	/**
	 * @param mixed $argument
	 *
	 * @throws QueryException
	 */
	protected function run($argument) {
		$app = \OC::$server->query(Application::class);
		$c = $app->getContainer();

		$this->configService = $c->query(ConfigService::class);
		$this->miscService = $c->query(MiscService::class);

		$size = (int)$this->configService->getAppValue(ConfigService::DATABASE_CHUNK_SIZE);
		$this->morphChunks($size);
	}


	/**
	 * @param int $size
	 */
	private function morphChunks(int $size) {

	}

}

