<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
	private ConfigService $configService;

	private MiscService $miscService;

	/**
	 * FediverseService constructor.
	 *
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ConfigService $configService, MiscService $miscService,
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

		if ($this->getAccessType()
			=== $this->configService->accessTypeList['BLACKLIST']
			&& !$this->isListed($address)) {
			return true;
		}

		if ($this->getAccessType()
			=== $this->configService->accessTypeList['WHITELIST']
			&& ($this->isExactlyListed($address) || $this->isLocal($address))) {
			// an allow list widens no further than what the admin wrote: a
			// subdomain of an allowed domain is a different instance, and
			// whoever runs the parent domain is not asked before one appears
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
		$list = json_decode($this->configService->getAppValue(ConfigService::SOCIAL_ACCESS_LIST));

		return is_array($list) ? array_values($list) : [];
	}

	/**
	 * Whether an address is covered by the instance access list.
	 *
	 * A listed domain covers the domain itself and everything under it, the way
	 * every other Fediverse implementation reads a domain block: matching the
	 * exact string only meant that blocking `evil.test` still let
	 * `www.evil.test` and `a.evil.test` straight back in, so a suspension
	 * lasted as long as it took to point another wildcard record at the same
	 * host. A trailing dot (the absolute form of the same name) is the same
	 * name, and case never matters in a hostname.
	 */
	public function isListed(string $address): bool {
		$host = $this->normalizeAddress($address);
		if ($host === '') {
			return false;
		}

		foreach ($this->getListedAddresses() as $listed) {
			$listed = $this->normalizeAddress((string)$listed);
			if ($listed === '') {
				continue;
			}

			if ($host === $listed || str_ends_with($host, '.' . $listed)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the access list carries this exact address — no subdomains.
	 *
	 * What an allow list permits, and what `addAddress()` treats as already
	 * known so an admin can still remove what they added.
	 */
	public function isExactlyListed(string $address): bool {
		$host = $this->normalizeAddress($address);
		if ($host === '') {
			return false;
		}

		foreach ($this->getListedAddresses() as $listed) {
			if ($this->normalizeAddress((string)$listed) === $host) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A hostname in the one form comparisons can be made in: lowercase, without
	 * the trailing dot of the absolute form, and without surrounding space.
	 */
	private function normalizeAddress(string $address): string {
		return rtrim(strtolower(trim($address)), '.');
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
		// isListed() already answers true for a subdomain of a listed domain;
		// the list itself stays a set of exact entries so an admin can remove
		// what they added
		if ($this->isExactlyListed($address)) {
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
		$list = array_values(array_udiff($this->getListedAddresses(), [$address], 'strcasecmp'));
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
