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
use daita\MySmallPhpTools\Traits\TPathTools;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\PreConditionNotMetException;


/**
 * Class ConfigService
 *
 * @package OCA\Social\Service
 */
class ConfigService {


	use TPathTools;
	use TArrayTools;


	const SOCIAL_ADDRESS = 'address';

	/** @var array */
	public $defaults = [
		self::SOCIAL_ADDRESS => ''
	];

	/** @var string */
	private $userId;

	/** @var IConfig */
	private $config;

	/** @var IRequest */
	private $request;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var MiscService */
	private $miscService;


	/**
	 * ConfigService constructor.
	 *
	 * @param string $userId
	 * @param IConfig $config
	 * @param IRequest $request
	 * @param IURLGenerator $urlGenerator
	 * @param MiscService $miscService
	 */
	public function __construct(
		$userId, IConfig $config, IRequest $request, IURLGenerator $urlGenerator,
		MiscService $miscService
	) {
		$this->userId = $userId;
		$this->config = $config;
		$this->request = $request;
		$this->urlGenerator = $urlGenerator;
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


	/**
	 * @param bool $host
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function getCloudAddress(bool $host = false) {
		$address = $this->getAppValue(self::SOCIAL_ADDRESS);
		if ($address === '') {
			throw new SocialAppConfigException();
		}

		if ($host === true) {
			$parsed = parse_url($address);
			$result = $this->get('host', $parsed, '');
			$port = $this->get('port', $parsed, '');
//			if ($port !== '') {
//				$result .= ':' . $port;
//			}

			return $result;
		}

		return $address;
	}


	/**
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function getRoot(): string {
		return $this->withoutEndSlash($this->getCloudAddress(), false, false) . '/apps/social/';
	}


	/**
	 * @param string $path
	 * @param bool $generateId
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function generateId(string $path = '', $generateId = true): string {
		$path = $this->withoutBeginSlash($this->withEndSlash($path));

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


