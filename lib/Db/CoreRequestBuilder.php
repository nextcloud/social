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
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Person;
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
	 * Limit the request to the ActorId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $actorId
	 */
	protected function limitToActorId(IQueryBuilder &$qb, string $actorId) {
		$this->limitToDBField($qb, 'actor_id', $actorId);
	}


	/**
	 * Limit the request to the FollowId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $followId
	 */
	protected function limitToFollowId(IQueryBuilder &$qb, string $followId) {
		$this->limitToDBField($qb, 'follow_id', $followId);
	}


	/**
	 * Limit the request to the ServiceId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $objectId
	 */
	protected function limitToObjectId(IQueryBuilder &$qb, string $objectId) {
		$this->limitToDBField($qb, 'object_id', $objectId);
	}


	/**
	 * Limit the request to the account
	 *
	 * @param IQueryBuilder $qb
	 * @param string $account
	 */
	protected function limitToAccount(IQueryBuilder &$qb, string $account) {
		$this->limitToDBField($qb, 'account', $account, false);
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
	 * @param int $since
	 * @param int $limit
	 */
	protected function limitPaginate(IQueryBuilder &$qb, int $since = 0, int $limit = 5) {
		if ($since > 0) {
			$expr = $qb->expr();
			$dt = new \DateTime();
			$dt->setTimestamp($since);
			// TODO: Pagination should use published date, once we can properly query the db for that
			$qb->andWhere(
				$expr->lt(
					$this->defaultSelectAlias . '.creation',
					$qb->createNamedParameter($dt, IQueryBuilder::PARAM_DATE),
					IQueryBuilder::PARAM_DATE
				)
			);
		}
		$qb->setMaxResults($limit);
		$qb->orderBy('creation', 'desc');
	}

	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param string|integer|array $values
	 * @param bool $cs Case Sensitive
	 */
	private function limitToDBField(IQueryBuilder &$qb, string $field, $values, bool $cs = true) {
		$expr = $qb->expr();
		$pf = ($qb->getType() === QueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$field = $pf . $field;

		if (!is_array($values)) {
			$values = [$values];
		}

		$orX = $expr->orX();
		foreach ($values as $value) {
			if ($cs) {
				$orX->add($expr->eq($field, $qb->createNamedParameter($value)));
			} else {
				$orX->add($expr->iLike($field, $qb->createNamedParameter($value)));
			}
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

		$qb->andWhere($expr->iLike($field, $qb->createNamedParameter($value)));
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $fieldActorId
	 */
	protected function leftJoinCacheActors(IQueryBuilder &$qb, string $fieldActorId) {

		if ($qb->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $qb->expr();
		$pf = $this->defaultSelectAlias;

//		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->selectAlias('ca.id', 'cacheactor_id')
		   ->selectAlias('ca.account', 'cacheactor_account')
		   ->selectAlias('ca.following', 'cacheactor_following')
		   ->selectAlias('ca.followers', 'cacheactor_followers')
		   ->selectAlias('ca.inbox', 'cacheactor_inbox')
		   ->selectAlias('ca.shared_inbox', 'cacheactor_shared_inbox')
		   ->selectAlias('ca.outbox', 'cacheactor_outbox')
		   ->selectAlias('ca.featured', 'cacheactor_featured')
		   ->selectAlias('ca.url', 'cacheactor_url')
		   ->selectAlias('ca.preferred_username', 'cacheactor_preferred_username')
		   ->selectAlias('ca.name', 'cacheactor_name')
		   ->selectAlias('ca.summary', 'cacheactor_summary')
		   ->selectAlias('ca.public_key', 'cacheactor_public_key')
		   ->selectAlias('ca.source', 'cacheactor_source')
		   ->selectAlias('ca.creation', 'cacheactor_creation')
		   ->leftJoin(
			   $this->defaultSelectAlias, CoreRequestBuilder::TABLE_CACHE_ACTORS, 'ca',
			   $expr->eq($pf . '.' . $fieldActorId, 'ca.id')
		   );
	}


	/**
	 * @param array $data
	 *
	 * @return Person
	 * @throws InvalidResourceException
	 */
	protected function parseCacheActorsLeftJoin(array $data): Person {
		$new = [];

		foreach ($data as $k => $v) {
			if (substr($k, 0, 11) === 'cacheactor_') {
				$new[substr($k, 11)] = $v;
			}
		}

		$actor = new Person();
		$actor->import($new);

		if ($actor->getType() !== Person::TYPE) {
			throw new InvalidResourceException();
		}

		return $actor;
	}

}



