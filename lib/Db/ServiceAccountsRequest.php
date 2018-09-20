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


use Exception;
use OCA\Social\Exceptions\ServiceAccountDoesNotExistException;
use OCA\Social\Model\ServiceAccount;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\IDBConnection;
use OCP\IL10N;

class ServiceAccountsRequest extends ServiceAccountsRequestBuilder {

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
	 * @param ServiceAccount $account
	 *
	 * @return int
	 * @throws Exception
	 */
	public function create(ServiceAccount $account): int {
		try {
			$service = $account->getService();
			$qb = $this->getAccountsInsertSql();
			$qb->setValue('service_id', $qb->createNamedParameter($service->getId()))
			   ->setValue('user_id', $qb->createNamedParameter($account->getUserId()))
			   ->setValue('account', $qb->createNamedParameter($account->getAccount()))
			   ->setValue('account_id', $qb->createNamedParameter($account->getAccountId()))
			   ->setValue('status', $qb->createNamedParameter($account->getStatus()))
			   ->setValue('auth', $qb->createNamedParameter(json_encode($account->getAuthAll())));

			$qb->execute();

			return $qb->getLastInsertId();
		} catch (Exception $e) {
			throw $e;
		}
	}


	/**
	 * @param string $userId
	 *
	 * @return ServiceAccount[]
	 * @throws Exception
	 */
	public function getAvailableAccounts(string $userId): array {
		try {
			$qb = $this->getAccountsSelectSql();
			$this->limitToUserId($qb, $userId);
			$this->limitToStatus($qb, 1);
			$this->leftJoinService($qb);

			$accounts = [];
			$cursor = $qb->execute();
			while ($data = $cursor->fetch()) {
				$accounts[] = $this->parseAccountsSelectSql($data);
			}
			$cursor->closeCursor();

			return $accounts;
		} catch (Exception $e) {
			throw $e;
		}
	}


	/**
	 * @param int $serviceId
	 * @param string $userId
	 * @param string $accountName
	 *
	 * @return ServiceAccount
	 * @throws ServiceAccountDoesNotExistException
	 */
	public function getFromAccountName(int $serviceId, string $userId, string $accountName) {
		$qb = $this->getAccountsSelectSql();
		$this->limitToUserId($qb, $userId);
		$this->limitToAccount($qb, $accountName);
		$this->limitToServiceId($qb, $serviceId);
		$this->leftJoinService($qb);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ServiceAccountDoesNotExistException($this->l10n->t('Account not found'));
		}

		return $this->parseAccountsSelectSql($data);

	}


	/**
	 * return account.
	 *
	 * @param int $accountId
	 *
	 * @return ServiceAccount
	 * @throws ServiceAccountDoesNotExistException
	 */
	public function getAccount(int $accountId): ServiceAccount {
		$qb = $this->getAccountsSelectSql();
		$this->limitToId($qb, $accountId);
		$this->leftJoinService($qb);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ServiceAccountDoesNotExistException($this->l10n->t('Account not found'));
		}

		return $this->parseAccountsSelectSql($data);
	}


//	/**
//	 * @param Service $service
//	 *
//	 * @return bool
//	 */
//	public function update(Service $service): bool {
//
//		try {
//			$this->getService($service->getId());
//		} catch (ServiceDoesNotExistException $e) {
//			return false;
//		}
//
//		$qb = $this->getServicesUpdateSql();
//		$qb->set('address', $qb->createNamedParameter($service->getAddress()));
//		$qb->set('config', $qb->createNamedParameter(json_encode($service->getConfigAll())));
//		$qb->set('status', $qb->createNamedParameter($service->getStatus()));
//		$qb->set('auth', $qb->createNamedParameter(''));
//		$qb->set('config', $qb->createNamedParameter(json_encode($service->getConfigAll())));
//
//		$this->limitToId($qb, $service->getId());
//
//		$qb->execute();
//
//		return true;
//	}
//
//
//	/**
//	 * @param int $serviceId
//	 */
//	public function delete(int $serviceId) {
//		$qb = $this->getServicesDeleteSql();
//		$this->limitToId($qb, $serviceId);
//
//		$qb->execute();
//	}
//
//

//
//	/**
//	 * return services.
//	 *
//	 * @return Service[]
//	 */
//	public function getServices(): array {
//		$qb = $this->getServicesSelectSql();
//
//		$services = [];
//		$cursor = $qb->execute();
//		while ($data = $cursor->fetch()) {
//			$services[] = $this->parseServicesSelectSql($data);
//		}
//		$cursor->closeCursor();
//
//		return $services;
//	}


}
