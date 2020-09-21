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


use daita\MySmallPhpTools\Traits\TArrayTools;
use OCA\Social\Db\InstancesRequest;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Instance;


class InstanceService {


	use TArrayTools;


	private $instancesRequest;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	public function __construct(
		InstancesRequest $instancesRequest, ConfigService $configService, MiscService $miscService
	) {
		$this->instancesRequest = $instancesRequest;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 *
	 */
	public function createLocal(): void {

	}

	/**
	 * @param int $format
	 *
	 * @return Instance
	 * @throws InstanceDoesNotExistException
	 */
	public function getLocal(int $format = ACore::FORMAT_LOCAL): Instance {
		try {
			return $this->instancesRequest->getLocal($format);
		} catch (InstanceDoesNotExistException $e) {
		}

		$this->createLocal();

		return $this->instancesRequest->getLocal($format);
	}

}

