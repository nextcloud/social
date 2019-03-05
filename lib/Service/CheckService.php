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


use daita\MySmallPhpTools\Traits\TArrayTools;
use daita\MySmallPhpTools\Traits\TStringTools;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;


/**
 * Class CheckService
 *
 * @package OCA\Social\Service
 */
class CheckService {


	use TArrayTools;
	use TStringTools;


	private $cache;
	private $config;
	private $clientService;
	private $request;
	private $urlGenerator;

	/** @var FollowsRequest */
	private $followRequest;

	const CACHE_PREFIX = 'social_check_';


	/**
	 * CheckService constructor.
	 *
	 * @param ICache $cache
	 * @param IConfig $config
	 * @param IClientService $clientService
	 * @param IRequest $request
	 * @param IURLGenerator $urlGenerator
	 * @param FollowsRequest $followRequest
	 */
	public function __construct(
		ICache $cache, IConfig $config, IClientService $clientService, IRequest $request,
		IURLGenerator $urlGenerator, FollowsRequest $followRequest
	) {
		$this->cache = $cache;
		$this->config = $config;
		$this->clientService = $clientService;
		$this->request = $request;
		$this->urlGenerator = $urlGenerator;
		$this->followRequest = $followRequest;
	}


	/**
	 * @return array
	 */
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
			'checks'  => $checks
		];
	}


	/**
	 * @return bool
	 */
	public function checkWellKnown(): bool {
		$state = (bool)($this->cache->get(self::CACHE_PREFIX . 'wellknown') === 'true');
		if ($state === true) {
			return true;
		}

		$address = $this->config->getAppValue('social', 'address', '');

		if ($address !== '' && $this->requestWellKnown($address)) {
			return true;
		}

		if ($this->requestWellKnown(
			$this->request->getServerProtocol() . '://' . $this->request->getServerHost()
		)) {
			return true;
		}

		if ($this->requestWellKnown($this->urlGenerator->getBaseUrl())) {
			return true;
		}

		return false;
	}


	/**
	 *
	 */
	public function checkInstallationStatus() {
		$this->checkStatusTableFollows();
	}


	/**
	 * create a fake follow entry. Mandatory to have Home Stream working.
	 */
	public function checkStatusTableFollows() {
		if ($this->followRequest->countFollows() > 0) {
			return;
		}

		$follow = new Follow();
		$follow->setId($this->uuid());
		$follow->setType('Unknown');
		$follow->setActorId($this->uuid());
		$follow->setObjectId($this->uuid());
		$follow->setFollowId($this->uuid());

		$this->followRequest->save($follow);
	}


	/**
	 * @param string $base
	 *
	 * @return bool
	 */
	private function requestWellKnown(string $base) {
		try {
			$url = $base . '/.well-known/webfinger';
			$response = $this->clientService->newClient()
											->get($url);
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
