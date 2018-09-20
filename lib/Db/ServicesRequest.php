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

namespace OCA\Social\Db;


use OCA\Social\Exceptions\ServiceDoesNotExistException;
use OCA\Social\Model\Service;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\IDBConnection;
use OCP\IL10N;

class ServicesRequest extends ServicesRequestBuilder {

	/** @var IL10N */
	private $l10n;


	/**
	 * ServicesRequest constructor.
	 *
	 * @param IL10N $l10n
	 * @param IDBConnection $connection
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IL10n $l10n, IDBConnection $connection, ConfigService $configService,
		MiscService $miscService
	) {
		parent::__construct($connection, $configService, $miscService);

		$this->l10n = $l10n;
	}


	/**
	 * @param string $type
	 * @param string $instance
	 *
	 * @return int
	 * @throws \Exception
	 */
	public function create(string $type, string $instance): int {

		try {
			$qb = $this->getServicesInsertSql();
			$qb->setValue('type', $qb->createNamedParameter($type))
			   ->setValue('address', $qb->createNamedParameter($instance))
			   ->setValue('status', $qb->createNamedParameter(Service::STATUS_SETUP));

			$qb->execute();

			return $qb->getLastInsertId();
		} catch (\Exception $e) {
			throw $e;
		}
	}


	/**
	 * @param Service $service
	 *
	 * @return bool
	 */
	public function update(Service $service): bool {

		try {
			$this->getService($service->getId());
		} catch (ServiceDoesNotExistException $e) {
			return false;
		}

		$qb = $this->getServicesUpdateSql();
		$qb->set('address', $qb->createNamedParameter($service->getAddress()));
		$qb->set('config', $qb->createNamedParameter(json_encode($service->getConfigAll())));
		$qb->set('status', $qb->createNamedParameter($service->getStatus()));
		$qb->set('config', $qb->createNamedParameter(json_encode($service->getConfigAll())));

		$this->limitToId($qb, $service->getId());

		$qb->execute();

		return true;
	}


	/**
	 * @param int $serviceId
	 */
	public function delete(int $serviceId) {
		$qb = $this->getServicesDeleteSql();
		$this->limitToId($qb, $serviceId);

		$qb->execute();
	}


	/**
	 * return service.
	 *
	 * @param int $serviceId
	 *
	 * @return Service
	 * @throws ServiceDoesNotExistException
	 */
	public function getService(int $serviceId): Service {
		$qb = $this->getServicesSelectSql();
		$this->limitToId($qb, $serviceId);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ServiceDoesNotExistException($this->l10n->t('Service not found'));
		}

		return $this->parseServicesSelectSql($data);
	}


	/**
	 * return service.
	 *
	 * @param string $instance
	 *
	 * @return Service
	 * @throws ServiceDoesNotExistException
	 */
	public function getServiceFromInstance(string $instance): Service {
		$qb = $this->getServicesSelectSql();
		$this->limitToAddress($qb, $instance);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ServiceDoesNotExistException($this->l10n->t('Service not found'));
		}

		return $this->parseServicesSelectSql($data);
	}


	/**
	 * return services.
	 *
	 * @param bool $validOnly
	 *
	 * @return Service[]
	 */
	public function getServices($validOnly = false): array {
		$qb = $this->getServicesSelectSql();

		if ($validOnly === true) {
			$this->limitToStatus($qb, 1);
		}

		$services = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$services[] = $this->parseServicesSelectSql($data, $validOnly);
		}
		$cursor->closeCursor();

		return $services;
	}


}
