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
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;


/**
 * Class FediverseService
 *
 * @package OCA\Social\Service
 */
class FediverseService {


	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * FediverseService constructor.
	 *
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ConfigService $configService, MiscService $miscService
	) {
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $address
	 *
	 * @return bool
	 * @throws UnauthorizedFediverseException
	 * @throws SocialAppConfigException
	 */
	public function authorized(string $address): bool {
		if ($address === '') {
			throw new UnauthorizedFediverseException('Empty Origin');
		}

		if ($this->getAccessType() ===
			$this->configService->accessTypeList['BLACKLIST']
			&& !$this->isListed($address)) {
			return true;
		}

		if ($this->getAccessType() ===
			$this->configService->accessTypeList['WHITELIST']
			&& ($this->isListed($address) || $this->isLocal($address))) {
			return true;
		}

		throw new UnauthorizedFediverseException('Unauthorized Fediverse');
	}


	/**
	 * @throws UnauthorizedFediverseException
	 */
	public function jailed() {
		if ($this->getAccessType() !== $this->configService->accessTypeList['WHITELIST']
			|| !empty($this->getListedAddresses())) {
			return;
		}

		throw new UnauthorizedFediverseException('Jailed Fediverse');
	}


	/**
	 * @return string
	 */
	public function getAccessType(): string {
		return $this->configService->getAppValue(ConfigService::SOCIAL_ACCESS_TYPE);
	}


	/**
	 * @param string $type
	 *
	 * @throws Exception
	 */
	public function setAccessType(string $type) {
		$accepted = array_values($this->configService->accessTypeList);
		if (!in_array($type, $accepted)) {
			throw new Exception('invalid type: ' . json_encode($accepted));
		}

		$this->configService->setAppValue(ConfigService::SOCIAL_ACCESS_TYPE, $type);
	}


	/**
	 * @param string $address
	 *
	 * @return bool
	 * @throws SocialAppConfigException
	 */
	public function isLocal(string $address): bool {
		$local = $this->configService->getCloudHost();

		return ($local === $address);
	}


	/**
	 * @return array
	 */
	public function getKnownAddresses(): array {
		return [];
	}


	/**
	 * @return array
	 */
	public function getListedAddresses(): array {
		return json_decode($this->configService->getAppValue(ConfigService::SOCIAL_ACCESS_LIST));
	}

	/**
	 * @param string $address
	 *
	 * @return bool
	 */
	public function isListed(string $address): bool {
		$list = $this->getListedAddresses();

		return (in_array($address, $list));
	}

	/**
	 *
	 */
	public function resetAddresses() {
		$this->configService->setAppValue(ConfigService::SOCIAL_ACCESS_LIST, '[]');
	}

	/**
	 * @param string $address
	 */
	public function addAddress(string $address) {
		if ($this->isListed($address)) {
			return;
		}

		$list = $this->getListedAddresses();
		array_push($list, $address);

		$this->configService->setAppValue(ConfigService::SOCIAL_ACCESS_LIST, json_encode($list));
	}

	/**
	 * @param string $address
	 *
	 * @return void
	 * @throws Exception
	 */
	public function removeAddress(string $address) {
		$list = $this->getListedAddresses();
		$list = array_diff($list, [$address]);
		$this->configService->setAppValue(ConfigService::SOCIAL_ACCESS_LIST, json_encode($list));
	}


//
//	/**
//	 * @param string $address
//	 *
//	 * @throws Exception
//	 */
//	public function blockAddress(string $address) {
//		if ($this->isBlocked($address)) {
//			return;
//		}
//
//		if ($this->isAllowed($address)) {
//			throw new Exception($address . ' is already in the whitelist');
//		}
//
//		$blackList = $this->getBlockedAddresses();
//		array_push($blackList, $address);
//
//		$this->configService->setAppValue(ConfigService::SOCIAL_BLACKLIST, json_encode($blackList));
//	}
//
//	/**
//	 * @return array
//	 */
//	public function getBlockedAddresses(): array {
//		return json_decode($this->configService->getAppValue(ConfigService::SOCIAL_BLACKLIST));
//	}
//
//	/**
//	 * @param string $address
//	 *
//	 * @return bool
//	 */
//	public function isBlocked(string $address): bool {
//		return (in_array('ALL', $this->getBlockedAddresses())
//				|| in_array($address, $this->getBlockedAddresses()));
//	}
//
//
//	/**
//	 * @param string $address
//	 *
//	 * @return void
//	 * @throws Exception
//	 */
//	public function allowAddress(string $address) {
//		if ($this->isAllowed($address)) {
//			return;
//		}
//
//		if ($this->isBlocked($address)) {
//			throw new Exception($address . ' is already in the blacklist');
//		}
//
//		$whiteList = $this->getAllowedAddresses();
//		array_push($whiteList, $address);
//
//		$this->configService->setAppValue(ConfigService::SOCIAL_WHITELIST, json_encode($whiteList));
//	}
//
//	/**
//	 * @return array
//	 */
//	public function getAllowedAddresses(): array {
//		return json_decode($this->configService->getAppValue(ConfigService::SOCIAL_WHITELIST));
//
//	}
//
//	/**
//	 * @param string $address
//	 *
//	 * @return bool
//	 */
//	public function isAllowed(string $address): bool {
//		return (in_array('ALL', $this->getAllowedAddresses())
//				|| in_array($address, $this->getAllowedAddresses()));
//	}
//
//

}

