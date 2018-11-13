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


use Doctrine\DBAL\Query\QueryBuilder;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class CoreRequestBuilder {

	const TABLE_SERVER_ACTORS = 'social_server_actors';
	const TABLE_SERVER_NOTES = 'social_server_notes';
	const TABLE_SERVER_FOLLOWS = 'social_server_follows';

	const TABLE_CACHE_ACTORS = 'social_cache_actors';


	/** @var IDBConnection */
	protected $dbConnection;

	/** @var ConfigService */
	protected $configService;

	/** @var MiscService */
	protected $miscService;


	/** @var string */
	protected $defaultSelectAlias;


	/**
	 * CoreRequestBuilder constructor.
	 *
	 * @param IDBConnection $connection
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IDBConnection $connection, ConfigService $configService, MiscService $miscService
	) {
		$this->dbConnection = $connection;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * Limit the request to the Id
	 *
	 * @param IQueryBuilder $qb
	 * @param int $id
	 */
	protected function limitToId(IQueryBuilder &$qb, int $id) {
		$this->limitToDBField($qb, 'id', $id);
	}


	/**
	 * Limit the request to the Id
	 *
	 * @param IQueryBuilder $qb
	 * @param string $id
	 */
	protected function limitToIdString(IQueryBuilder &$qb, string $id) {
		$this->limitToDBField($qb, 'id', $id);
	}


	/**
	 * Limit the request to the OwnerId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $userId
	 */
	protected function limitToUserId(IQueryBuilder &$qb, $userId) {
		$this->limitToDBField($qb, 'user_id', $userId);
	}


	/**
	 * Limit the request to the OwnerId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $userId
	 */
	protected function limitToPreferredUsername(IQueryBuilder &$qb, $userId) {
		$this->limitToDBField($qb, 'preferred_username', $userId);
	}

	/**
	 * Limit the request to the OwnerId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $username
	 */
	protected function searchInPreferredUsername(IQueryBuilder &$qb, $username) {
		$this->searchInDBField($qb, 'preferred_username', $username . '%');
	}


	/**
	 * Limit the request to the OwnerId
	 *
	 * @param IQueryBuilder $qb
	 * @param int $accountId
	 */
	protected function limitToAccountId(IQueryBuilder &$qb, int $accountId) {
		$this->limitToDBField($qb, 'account_id', $accountId);
	}


	/**
	 * Limit the request to the ServiceId
	 *
	 * @param IQueryBuilder $qb
	 * @param int $serviceId
	 */
	protected function limitToServiceId(IQueryBuilder &$qb, int $serviceId) {
		$this->limitToDBField($qb, 'service_id', $serviceId);
	}


	/**
	 * Limit the request to the account
	 *
	 * @param IQueryBuilder $qb
	 * @param string $account
	 */
	protected function limitToAccount(IQueryBuilder &$qb, string $account) {
		$this->limitToDBField($qb, 'account', $account);
	}


	/**
	 * Limit the request to the account
	 *
	 * @param IQueryBuilder $qb
	 * @param string $account
	 */
	protected function searchInAccount(IQueryBuilder &$qb, string $account) {
		$this->searchInDBField($qb, 'account', $account . '%');
	}


	/**
	 * Limit the request to the url
	 *
	 * @param IQueryBuilder $qb
	 * @param string $url
	 */
	protected function limitToUrl(IQueryBuilder &$qb, string $url) {
		$this->limitToDBField($qb, 'url', $url);
	}


	/**
	 * Limit the request to the status
	 *
	 * @param IQueryBuilder $qb
	 * @param string $status
	 */
	protected function limitToStatus(IQueryBuilder &$qb, $status) {
		$this->limitToDBField($qb, 'status', $status);
	}


	/**
	 * Limit the request to the instance
	 *
	 * @param IQueryBuilder $qb
	 * @param string $address
	 */
	protected function limitToAddress(IQueryBuilder &$qb, $address) {
		$this->limitToDBField($qb, 'address', $address);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $recipient
	 */
	protected function limitToRecipient(IQueryBuilder &$qb, string $recipient) {
		$expr = $qb->expr();
		$orX = $expr->orX();

		$orX->add($expr->eq('to', $qb->createNamedParameter($recipient)));
		$orX->add($expr->like('to_array', $qb->createNamedParameter('%"' . $recipient . '"%')));
		$orX->add($expr->like('cc', $qb->createNamedParameter('%"' . $recipient . '"%')));
		$orX->add($expr->like('bcc', $qb->createNamedParameter('%"' . $recipient . '"%')));

		$qb->andWhere($orX);
	}

	/**
	 * @param IQueryBuilder $qb
	 * @param string $recipient
	 */
	protected function limitPaginate(IQueryBuilder &$qb, int $since = 0, int $limit = 5) {
		if ($since > 0) {
			$expr = $qb->expr();
			$dt = new \DateTime();
			$dt->setTimestamp($since);
			$qb->andWhere('creation < "2020-10-10 10:00:00"');
		}
		$qb->setMaxResults($limit);
		$qb->orderBy('creation', 'desc');
	}

	/**
	 * @param IQueryBuilder $qb
	 * @param string $field"
	 * @param string|integer|array $values
	 */
	private function limitToDBField(IQueryBuilder &$qb, $field, $values) {
		$expr = $qb->expr();
		$pf = ($qb->getType() === QueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$field = $pf . $field;

		if (!is_array($values)) {
			$values = [$values];
		}

		$orX = $expr->orX();
		foreach ($values as $value) {
			$orX->add($expr->eq($field, $qb->createNamedParameter($value)));
		}

		$qb->andWhere($orX);
	}

	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param string $value
	 */
	private function searchInDBField(IQueryBuilder &$qb, string $field, string $value) {
		$expr = $qb->expr();
		$pf = ($qb->getType() === QueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$field = $pf . $field;

		$qb->andWhere($expr->like($field, $qb->createNamedParameter($value)));
	}

//	/**
//	 * Left Join service to get info about the serviceId
//	 *
//	 * @param IQueryBuilder $qb
//	 */
//	public function leftJoinService(IQueryBuilder &$qb) {
//
//		if ($qb->getType() !== QueryBuilder::SELECT) {
//			return;
//		}
//
//		$expr = $qb->expr();
//		$pf = $this->defaultSelectAlias;
//
//		/** @noinspection PhpMethodParametersCountMismatchInspection */
//		$qb->selectAlias('s.address', 'service_address')
//		   ->selectAlias('s.status', 'service_status')
//		   ->selectAlias('s.config', 'service_config')
//		   ->selectAlias('s.type', 'service_type')
//		   ->leftJoin(
//			   $this->defaultSelectAlias, CoreRequestBuilder::TABLE_SERVICES, 's',
//			   $expr->eq($pf . '.service_id', 's.id')
//		   );
//	}

}



