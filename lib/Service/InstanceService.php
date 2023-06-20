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
use OCA\Social\Db\InstancesRequest;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Instance;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IConfig;

class InstanceService {
	use TArrayTools;

	private InstancesRequest $instancesRequest;
	private ConfigService $configService;
	private MiscService $miscService;
	private IConfig $config;

	public function __construct(
		InstancesRequest $instancesRequest,
		ConfigService $configService,
		MiscService $miscService,
		IConfig $config
	) {
		$this->instancesRequest = $instancesRequest;
		$this->configService = $configService;
		$this->miscService = $miscService;
		$this->config = $config;
	}

	public function createLocal(): Instance {
		$instance = new Instance();
		$instance->setLocal(true)
			->setVersion($this->config->getAppValue(Application::APP_ID, 'installed_version', '0.0'))
			->setApprovalRequired(false)
			->setDescription($this->config->getAppValue('theming', 'slogan', 'a safe home for your data'))
			->setTitle($this->config->getAppValue('theming', 'name', 'Nextcloud Social'));
		$this->instancesRequest->save($instance);

		return $instance;
	}

	/**
	 * @throws InstanceDoesNotExistException
	 */
	public function getLocal(int $format = ACore::FORMAT_LOCAL): Instance {
		try {
			return $this->instancesRequest->getLocal($format);
		} catch (InstanceDoesNotExistException $e) {
		}

		return $this->createLocal();
	}
}
