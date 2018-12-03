<?php
/**
 * @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 *
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Social\Service;


use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\IConfig;
use OCP\IRequest;

class CheckService {

	private $cache;
	private $config;

	const CACHE_PREFIX = 'social_check_';


	public function __construct(ICache $cache, IConfig $config, IClientService $clientService, IRequest $request) {
		$this->cache = $cache;
		$this->config = $config;
		$this->clientService = $clientService;
		$this->request = $request;
	}

	public function checkDefault(): array {
		$checks = [];
		$checks['wellknown'] = $this->checkWellKnown();

		$success = true;
		foreach ($checks as $check) {
			if (!$check) {
				$success = false;
			}
		}
		return [
			'success' => $success,
			'checks' => $checks
		];
	}
	public function checkWellKnown(): bool {
		$state = (bool) ($this->cache->get(self::CACHE_PREFIX . 'wellknown') === 'true');
		if ($state === true) {
			return true;
		}
		try {
			$url = $this->request->getServerProtocol() . '://' . $this->request->getServerHost() . '/.well-known/webfinger';
			$response = $this->clientService->newClient()->get($url);
			if ($response->getStatusCode() === Http::STATUS_OK) {
				$this->cache->set(self::CACHE_PREFIX . 'wellknown', 'true', 3600);
				return true;
			}
		} catch (\GuzzleHttp\Exception\ClientException $e) {
		} catch (\Exception $e) {
		}

		try {
			$url = \OC::$server->getURLGenerator()->getBaseUrl() . '/.well-known/webfinger';
			$response = $this->clientService->newClient()->get($url);
			if ($response->getStatusCode() === Http::STATUS_OK) {
				$this->cache->set(self::CACHE_PREFIX . 'wellknown', 'true', 3600);
				return true;
			}
		} catch (\GuzzleHttp\Exception\ClientException $e) {
		} catch (\Exception $e) {
		}
		return false;
	}

}
