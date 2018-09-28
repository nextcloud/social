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
use OCP\IConfig;
use OCP\IRequest;
use OCP\PreConditionNotMetException;


class ConfigService {

	/** @var array */
	public $defaults = [
	];

//	public $serviceTypes = [
//		[
//			'id'   => 'mastodon',
//			'name' => 'Mastodon (OAuth2)'
//		]
//	];

	/** @var string */
	private $userId;

	/** @var IConfig */
	private $config;

	/** @var IRequest */
	private $request;

	/** @var MiscService */
	private $miscService;


	/**
	 * ConfigService constructor.
	 *
	 * @param string $userId
	 * @param IConfig $config
	 * @param IRequest $request
	 * @param MiscService $miscService
	 */
	public function __construct(
		$userId, IConfig $config, IRequest $request,
		MiscService $miscService
	) {
		$this->userId = $userId;
		$this->config = $config;
		$this->request = $request;
		$this->miscService = $miscService;
	}


	/**
	 * Get a value by key
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	public function getAppValue($key) {
		$defaultValue = null;
		if (array_key_exists($key, $this->defaults)) {
			$defaultValue = $this->defaults[$key];
		}

		return $this->config->getAppValue(Application::APP_NAME, $key, $defaultValue);
	}

	/**
	 * Set a value by key
	 *
	 * @param string $key
	 * @param string $value
	 *
	 * @return void
	 */
	public function setAppValue($key, $value) {
		$this->config->setAppValue(Application::APP_NAME, $key, $value);
	}

	/**
	 * remove a key
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	public function deleteAppValue($key) {
		return $this->config->deleteAppValue(Application::APP_NAME, $key);
	}

	/**
	 * Get a user value by key
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	public function getUserValue($key) {
		$defaultValue = null;
		if (array_key_exists($key, $this->defaults)) {
			$defaultValue = $this->defaults[$key];
		}

		return $this->config->getUserValue(
			$this->userId, Application::APP_NAME, $key, $defaultValue
		);
	}

	/**
	 * Set a user value by key
	 *
	 * @param string $key
	 * @param string $value
	 *
	 * @return string
	 * @throws PreConditionNotMetException
	 */
	public function setUserValue($key, $value) {
		return $this->config->setUserValue($this->userId, Application::APP_NAME, $key, $value);
	}

	/**
	 * Get a user value by key and user
	 *
	 * @param string $userId
	 * @param string $key
	 *
	 * @return string
	 */
	public function getValueForUser($userId, $key) {
		return $this->config->getUserValue($userId, Application::APP_NAME, $key);
	}

	/**
	 * Set a user value by key
	 *
	 * @param string $userId
	 * @param string $key
	 * @param string $value
	 *
	 * @return string
	 * @throws PreConditionNotMetException
	 */
	public function setValueForUser($userId, $key, $value) {
		return $this->config->setUserValue($userId, Application::APP_NAME, $key, $value);
	}


	/**
	 * @param string $key
	 * @param string $value
	 */
	public function setCoreValue(string $key, string $value) {
		$this->config->setAppValue('core', $key, $value);
	}


	/**
	 * @param $key
	 *
	 * @return mixed
	 */
	public function getSystemValue($key) {
		return $this->config->getSystemValue($key, '');
	}

	public function getCloudAddress() {
		return $this->request->getServerHost();
	}


	/**
	 * @return string
	 */
	public function getRoot(): string {
		return 'https://test.artificial-owl.com/apps/social/';
	}


	/**
	 * @param string $path
	 * @param bool $generateId
	 *
	 * @return string
	 */
	public function generateId(string $path = '', $generateId = true): string {
		$this->miscService->formatPath($path);

		$id = $this->getRoot() . $path;
		if ($generateId === true) {
			$id .= time() . crc32(uniqid());
		}

		return $id;
	}

//	/**
//	 * @return array
//	 */
//	public function getServiceTypes(): array {
//		return $this->serviceTypes;
//	}

}


