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
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Tools\Model\NCRequest;
use OCA\Social\Tools\Model\Request;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TPathTools;
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


	public const CLOUD_URL = 'cloud_url';
	public const SOCIAL_URL = 'social_url';
	public const SOCIAL_ADDRESS = 'social_address';

	public const SOCIAL_SERVICE = 'service';
	public const SOCIAL_MAX_SIZE = 'max_size';
	public const SOCIAL_ACCESS_TYPE = 'access_type';
	public const SOCIAL_ACCESS_LIST = 'access_list';

	public const SOCIAL_SELF_SIGNED = 'allow_self_signed';
	public const SOCIAL_DISABLE_HTTPS = 'disable_https';

	public const BACKGROUND_CRON = 1;
	public const BACKGROUND_ASYNC = 2;
	public const BACKGROUND_SERVICE = 3;
	public const BACKGROUND_FULL_SERVICE = 4;

	public array $defaults = [
		self::CLOUD_URL => '',
		self::SOCIAL_URL => '',
		self::SOCIAL_ADDRESS => '',
		self::SOCIAL_SERVICE => 1,
		self::SOCIAL_MAX_SIZE => 10,
		self::SOCIAL_ACCESS_TYPE => 'all_but',
		self::SOCIAL_ACCESS_LIST => '[]',
		self::SOCIAL_SELF_SIGNED => '0'
	];

	public array $accessTypeList = [
		'BLACKLIST' => 'all_but',
		'WHITELIST' => 'none_but'
	];

	private ?string $userId = null;
	private IConfig $config;
	private IRequest $request;
	private IURLGenerator $urlGenerator;
	private MiscService $miscService;

	public function __construct(
		?string $userId, IConfig $config, IRequest $request, IURLGenerator $urlGenerator,
		MiscService $miscService
	) {
		$this->userId = $userId;
		$this->config = $config;
		$this->request = $request;
		$this->urlGenerator = $urlGenerator;
		$this->miscService = $miscService;
	}


	/**
	 * @return array
	 */
	public function getConfig(): array {
		$keys = array_keys($this->defaults);
		$data = [];

		foreach ($keys as $k) {
			$data[$k] = $this->getAppValue($k);
		}

		return $data;
	}


	/**
	 * /**
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
	 * Get a value by key
	 *
	 * @param string $key
	 *
	 * @return int
	 */
	public function getAppValueInt(string $key): int {
		$defaultValue = null;
		if (array_key_exists($key, $this->defaults)) {
			$defaultValue = $this->defaults[$key];
		}

		return (int)$this->config->getAppValue(Application::APP_NAME, $key, $defaultValue);
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
	 * @param string $userId
	 * @param string $app
	 *
	 * @return string
	 */
	public function getUserValue(string $key, string $userId = '', string $app = '') {
		if ($userId === '') {
			$userId = $this->userId;
		}

		$defaultValue = '';
		if ($app === '') {
			$app = Application::APP_NAME;
			if (array_key_exists($key, $this->defaults)) {
				$defaultValue = $this->defaults[$key];
			}
		}

		return $this->config->getUserValue($userId, $app, $key, $defaultValue);
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
	 * @param string $key
	 *
	 * @return string
	 */
	public function getCoreValue(string $key): string {
		return $this->config->getAppValue('core', $key, '');
	}

	/**
	 * @param string $key
	 */
	public function unsetCoreValue(string $key) {
		$this->config->deleteAppValue('core', $key);
	}


	/**
	 *
	 */
	public function unsetAppConfig() {
		$this->config->deleteAppValues(Application::APP_NAME);
	}


	/**
	 * @param $key
	 *
	 * @return mixed
	 *
	 * @psalm-param string $key
	 */
	public function getSystemValue(string $key) {
		return $this->config->getSystemValue($key, '');
	}


	/**
	 * getCloudHost - cloud.example.com
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function getCloudHost(): string {
		$url = $this->getCloudUrl();

		return parse_url($url, PHP_URL_HOST);
	}


	/**
	 * getCloudUrl - https://cloud.example.com/index.php
	 *             - https://cloud.example.com
	 *
	 * @param bool $noPhp
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function getCloudUrl(bool $noPhp = false) {
		$address = $this->getAppValue(self::CLOUD_URL);
		if ($address === '') {
			throw new SocialAppConfigException();
		}

		if ($noPhp) {
			$pos = strpos($address, '/index.php');
			if ($pos) {
				$address = substr($address, 0, $pos);
			}
		}

		return $this->withoutEndSlash($address, false, false);
	}

	/**
	 * @param string $cloudAddress
	 */
	public function setCloudUrl(string $cloudAddress) {
		if (parse_url($cloudAddress, PHP_URL_SCHEME) === null) {
			$cloudAddress = 'http://' . $cloudAddress;
		}

		$this->setAppValue(self::CLOUD_URL, $cloudAddress);
	}


	/**
	 * getSocialAddress - example.com
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function getSocialAddress(): string {
		$address = $this->getAppValue(self::SOCIAL_ADDRESS);

		if ($address === '') {
			return $this->getCloudHost();
		}

		return $address;
	}

	/**
	 * @param string $address
	 */
	public function setSocialAddress(string $address) {
		$this->setAppValue(self::SOCIAL_ADDRESS, $address);
	}


	/**
	 * getSocialUrl - https://cloud.example.com/apps/social/
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function getSocialUrl(): string {
		$socialUrl = $this->getAppValue(self::SOCIAL_URL);
		if ($socialUrl === '') {
			throw new SocialAppConfigException();
		}

		return $socialUrl;
	}

	/**
	 * @param string $url
	 *
	 * @throws SocialAppConfigException
	 */
	public function setSocialUrl(string $url = '') {
		if ($url === '') {
			$url = $this->getCloudUrl(true) . $this->urlGenerator->linkToRoute(
				'social.Navigation.navigate'
			);
		}

		if (parse_url($url, PHP_URL_SCHEME) === null) {
			$url = 'http://' . $url;
		}

		$this->setAppValue(self::SOCIAL_URL, $url);
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

		$id = $this->getSocialUrl() . $path;
		if ($generateId === true) {
			$id .= time() . crc32(uniqid());
		}

		return $id;
	}


	public function configureRequest(NCRequest $request): void {
		$request->setVerifyPeer($this->getAppValue(ConfigService::SOCIAL_SELF_SIGNED) !== '1');
		if ($this->getAppValue(ConfigService::SOCIAL_DISABLE_HTTPS) === 'yes_i_only_run_test') {
			$request->setProtocols(['http']);
		}

		// do not add json headers if required
		if (!$this->getBool('ignoreJsonHeaders', $request->getClientOptions())) {
			if ($request->getType() === Request::TYPE_GET) {
				$request->addHeader(
					'Accept', 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"'
				);
			}

			if ($request->getType() === Request::TYPE_POST) {
				$request->addHeader(
					'Content-Type', 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"'
				);
			}
		}

		$request->setLocalAddressAllowed(true);
		$request->setFollowLocation(true);
	}
}
