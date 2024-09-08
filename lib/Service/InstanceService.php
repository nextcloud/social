<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
