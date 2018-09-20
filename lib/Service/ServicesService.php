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


use daita\Model\Request;
use daita\Traits\TArrayTools;
use Exception;
use OCA\Social\Db\ServicesRequest;
use OCA\Social\Exceptions\MissingStuffException;
use OCA\Social\Exceptions\ServiceDoesNotExistException;
use OCA\Social\Model\Service;
use OCA\Social\Model\ServiceAccount;
use OCA\Social\Traits\TOAuth2;

class ServicesService {


	use TOAuth2;
	use TArrayTools;


	/** @var ServicesRequest */
	private $servicesRequest;

	/** @var ConfigService */
	private $configService;

	/** @var CurlService */
	private $curlService;

	/** @var MiscService */
	private $miscService;


	/**
	 * ServicesService constructor.
	 *
	 * @param ServicesRequest $servicesRequest
	 * @param ConfigService $configService
	 * @param CurlService $curlService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ServicesRequest $servicesRequest, ConfigService $configService, CurlService $curlService,
		MiscService $miscService
	) {
		$this->servicesRequest = $servicesRequest;
		$this->configService = $configService;
		$this->curlService = $curlService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $instance
	 *
	 * @return Service
	 * @throws Exception
	 */
	public function createFromInstance(string $instance) {
		try {
			$service = $this->servicesRequest->getServiceFromInstance($instance);

			if ($service->getStatus() !== Service::STATUS_VALID) {
				$this->servicesRequest->delete($service->getId());
				throw new ServiceDoesNotExistException();
			}
		} catch (ServiceDoesNotExistException $e) {

			$service = $this->createService('mastodon', $instance);
			$app = $this->createAppOnService($service);

			$service->setConfig('clientKey', $this->get('client_id', $app, ''))
					->setConfig('clientSecret', $this->get('client_secret', $app, ''))
					->unsetConfig('redirectUrl')
					->setStatus(Service::STATUS_VALID);
			$this->servicesRequest->update($service);
		}

		return $service;
	}


	/**
	 * @param string $type
	 * @param string $address
	 *
	 * @return Service
	 * @throws Exception
	 */
	public function createService(string $type, string $address): Service {
		if ($type === '' || $address === '') {
			throw new MissingStuffException('missing some data');
		}

		$serviceId = $this->servicesRequest->create($type, $address);

		$service = $this->servicesRequest->getService($serviceId);
		$service->setConfig('redirectUrl', $this->generateRedirectUrl($serviceId));

		return $service;
	}


	/**
	 * @param Service $service
	 *
	 * @return array
	 * @throws Exception
	 */
	private function createAppOnService(Service $service) {

		$account = new ServiceAccount();
		$account->setService($service);

		$data = [
			'client_name'   => 'Social@' . $this->configService->getCloudAddress(),
			'redirect_uris' => $this->generateRedirectUrl($service->getId()),
			'scopes'        => 'read write follow',
			'website'       => 'https://' . $this->configService->getCloudAddress() . '/'
		];

		$request = new Request(ActivityStreamsService::URL_CREATE_APP, Request::TYPE_POST);
		$request->setData($data);

		return $this->curlService->request($account, $request, false);
	}


	/**
	 * @param int $serviceId
	 *
	 * @return Service
	 * @throws ServiceDoesNotExistException
	 */
	public function getService(int $serviceId): Service {
		$service = $this->servicesRequest->getService($serviceId);
		$service->setConfig('redirectUrl', $this->generateRedirectUrl($serviceId));

		return $service;
	}


	/**
	 * @param int $serviceId
	 * @param array $data
	 *
	 * @return Service
	 * @throws ServiceDoesNotExistException
	 */
	public function editService(int $serviceId, array $data): Service {

		$service = $this->servicesRequest->getService($serviceId);

		$service->setAddress($this->get('address', $data, ''));
		$service->setConfig('clientKey', trim($this->get('clientKey', $data, '')));
		$service->setConfig('clientSecret', trim($this->get('clientSecret', $data, '')));

		$this->validateService($service);
		$this->servicesRequest->update($service);

		$service->setConfig('redirectUrl', $this->generateRedirectUrl($serviceId));

		return $service;
	}


	/**
	 * @param Service $service
	 */
	private function validateService(Service &$service) {
		if ($service->getStatus() === 1
			|| $service->getConfig('clientKey', '') === ''
			|| $service->getConfig('clientSecret', '') === '') {
			return;
		}

		$service->setStatus(1);
	}


	/**
	 * @param int $serviceId
	 *
	 * @return Service[]
	 * @throws Exception
	 */
	public function removeService(int $serviceId): array {
		$this->servicesRequest->delete($serviceId);

		return $this->servicesRequest->getServices();
	}


	/**
	 * @return array
	 * @throws Exception
	 */
	public function getServices(): array {
		return $this->servicesRequest->getServices();
	}


	/**
	 * @return array
	 * @throws Exception
	 */
	public function getAvailableServices(): array {
		return $this->servicesRequest->getServices(true);
	}


}

