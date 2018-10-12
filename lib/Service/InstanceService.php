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


use daita\MySmallPhpTools\Model\Request;
use OCA\Social\Exceptions\RequestException;
use OCA\Social\Model\ActivityPub\Core;
use OCA\Social\Model\Instance;
use OCA\Social\Model\InstancePath;

class InstanceService {


	/** @var ConfigService */
	private $configService;


	private $curlService;

	/** @var MiscService */
	private $miscService;


	/**
	 * UriIdService constructor.
	 *
	 * @param ConfigService $configService
	 * @param CurlService $curlService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ConfigService $configService, CurlService $curlService, MiscService $miscService
	) {
		$this->configService = $configService;
		$this->curlService = $curlService;
		$this->miscService = $miscService;
	}


	/**
	 * @param $id
	 *
	 * @return mixed
	 * @throws RequestException
	 */
	public function retrieveObject($id) {
		$url = parse_url($id);

		$request = new Request($url['path'], Request::TYPE_GET);
		$request->setAddress($url['host']);

//		$key = $url['fragment'];

		return $this->curlService->request($request);
	}


	/**
	 * @param Core $activity
	 *
	 * @return Instance[]
	 */
	public function getInstancesFromActivity(Core $activity): array {
		$instances = [];
		foreach ($activity->getInstancePaths() as $instancePath) {
			$this->addInstances($instancePath, $instances);
//			$uriIds[] = $to;
		}

		return $instances;
	}


	/**
	 * @param InstancePath $instancePath
	 * @param Instance[] $instances
	 */
	private function addInstances(InstancePath $instancePath, array &$instances) {
		$address = $this->getHostFromUriId($instancePath->getUri());
		if ($address === '') {
			return;
		}

		foreach ($instances as $instance) {
			if ($instance->getAddress() === $address) {
				$instance->addPath($instancePath);

				return;
			}
		}

		$instance = new Instance($address);
		$instance->addPath($instancePath);
		$instances[] = $instance;
	}


	/**
	 * @param string $uriId
	 *
	 * @return string
	 */
	private function getHostFromUriId(string $uriId) {
		$ignoreThose = [
			'',
			'https://www.w3.org/ns/activitystreams#Public'
		];

		if (in_array($uriId, $ignoreThose)) {
			return '';
		}

		$url = parse_url($uriId);
		if (!is_array($url) || !array_key_exists('host', $url)) {
			return '';
		}

		return $url['host'];
	}


}

