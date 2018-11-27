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


use DateInterval;
use DateTime;
use Doctrine\DBAL\Query\QueryBuilder;
use Exception;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Document;
use OCA\Social\Model\ActivityPub\Image;
use OCA\Social\Model\ActivityPub\Person;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;


/**
 * Class CoreRequestBuilder
 *
 * @package OCA\Social\Db
 */
class CoreRequestBuilder {


	const TABLE_REQUEST_QUEUE = 'social_request_queue';

	const TABLE_SERVER_ACTORS = 'social_server_actors';
	const TABLE_SERVER_NOTES = 'social_server_notes';
	const TABLE_SERVER_FOLLOWS = 'social_server_follows';

	const TABLE_CACHE_ACTORS = 'social_cache_actors';
	const TABLE_CACHE_DOCUMENTS = 'social_cache_documents';


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
		$this->limitToDBFieldInt($qb, 'id', $id);
	}


	/**
	 * Limit the request to the Id (string)
	 *
	 * @param IQueryBuilder $qb
	 * @param string $id
	 */
	protected function limitToIdString(IQueryBuilder &$qb, string $id) {
		$this->limitToDBField($qb, 'id', $id);
	}


	/**
	 * Limit the request to the UserId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $userId
	 */
	protected function limitToUserId(IQueryBuilder &$qb, string $userId) {
		$this->limitToDBField($qb, 'user_id', $userId);
	}


	/**
	 * Limit the request to the Preferred Username
	 *
	 * @param IQueryBuilder $qb
	 * @param string $username
	 */
	protected function limitToPreferredUsername(IQueryBuilder &$qb, string $username) {
		$this->limitToDBField($qb, 'preferred_username', $username);
	}

	/**
	 * search using username
	 *
	 * @param IQueryBuilder $qb
	 * @param string $username
	 */
	protected function searchInPreferredUsername(IQueryBuilder &$qb, string $username) {
		$dbConn = $this->dbConnection;
		$this->searchInDBField(
			$qb, 'preferred_username', $dbConn->escapeLikeParameter($username) . '%'
		);
	}


	/**
	 * Limit the request to the ActorId
	 *
	 * @param IQueryBuilder $qb
	 */
	protected function limitToPublic(IQueryBuilder &$qb) {
		$this->limitToDBFieldInt($qb, 'public', 1);
	}


	/**
	 * Limit the request to the token
	 *
	 * @param IQueryBuilder $qb
	 * @param string $token
	 */
	protected function limitToToken(IQueryBuilder &$qb, string $token) {
		$this->limitToDBField($qb, 'token', $token);
	}


	/**
	 * Limit the request to the ActorId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $actorId
	 * @param string $alias
	 */
	protected function limitToActorId(IQueryBuilder &$qb, string $actorId, string $alias = '') {
		$this->limitToDBField($qb, 'actor_id', $actorId, true, $alias);
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
		$dbConn = $this->dbConnection;
		$this->searchInDBField($qb, 'account', $dbConn->escapeLikeParameter($account) . '%');
	}


	/**
	 * Limit the request to the creation
	 *
	 * @param IQueryBuilder $qb
	 * @param int $delay
	 *
	 * @throws Exception
	 */
	protected function limitToCreation(IQueryBuilder &$qb, int $delay = 0) {
		$date = new DateTime('now');
		$date->sub(new DateInterval('PT' . $delay . 'M'));

		$this->limitToDBFieldDateTime($qb, 'creation', $date);
	}


	/**
	 * Limit the request to the creation
	 *
	 * @param IQueryBuilder $qb
	 * @param int $delay
	 *
	 * @throws Exception
	 */
	protected function limitToCaching(IQueryBuilder &$qb, int $delay = 0) {
		$date = new DateTime('now');
		$date->sub(new DateInterval('PT' . $delay . 'M'));

		$this->limitToDBFieldDateTime($qb, 'caching', $date);
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
	 * @param int $status
	 */
	protected function limitToStatus(IQueryBuilder &$qb, int $status) {
		$this->limitToDBFieldInt($qb, 'status', $status);
	}


	/**
	 * Limit the request to the instance
	 *
	 * @param IQueryBuilder $qb
	 * @param string $address
	 */
	protected function limitToAddress(IQueryBuilder &$qb, string $address) {
		$this->limitToDBField($qb, 'address', $address);
	}


	/**
	 * Limit the request to the instance
	 *
	 * @param IQueryBuilder $qb
	 * @param bool $local
	 */
	protected function limitToLocal(IQueryBuilder &$qb, bool $local) {
		$this->limitToDBField($qb, 'local', ($local) ? '1' : '0');
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $recipient
	 */
	protected function limitToRecipient(IQueryBuilder &$qb, string $recipient) {
		$expr = $qb->expr();
		$orX = $expr->orX();
		$dbConn = $this->dbConnection;

		$orX->add($expr->eq('attributed_to', $qb->createNamedParameter($recipient)));
		$orX->add($expr->eq('to', $qb->createNamedParameter($recipient)));
		$orX->add(
			$expr->like(
				'to_array',
				$qb->createNamedParameter('%"' . $dbConn->escapeLikeParameter($recipient) . '"%')
			)
		);
		$orX->add(
			$expr->like(
				'cc',
				$qb->createNamedParameter('%"' . $dbConn->escapeLikeParameter($recipient) . '"%')
			)
		);
		$orX->add(
			$expr->like(
				'bcc',
				$qb->createNamedParameter('%"' . $dbConn->escapeLikeParameter($recipient) . '"%')
			)
		);

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
	 */
	protected function orderByPriority(IQueryBuilder &$qb) {
		$qb->orderBy('priority', 'desc');
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param string $value
	 * @param bool $cs - case sensitive
	 * @param string $alias
	 */
	protected function limitToDBField(
		IQueryBuilder &$qb, string $field, string $value, bool $cs = true, string $alias = ''
	) {
		$expr = $qb->expr();

		$pf = '';
		if ($qb->getType() === QueryBuilder::SELECT) {
			$pf = (($alias === '') ? $this->defaultSelectAlias : $alias) . '.';
		}
		$field = $pf . $field;

		if ($cs) {
			$qb->andWhere($expr->eq($field, $qb->createNamedParameter($value)));
		} else {
			$func = $qb->func();
			$qb->andWhere(
				$expr->eq($func->lower($field), $func->lower($qb->createNamedParameter($value)))
			);
		}
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param int $value
	 */
	protected function limitToDBFieldInt(IQueryBuilder &$qb, string $field, int $value) {
		$expr = $qb->expr();
		$pf = ($qb->getType() === QueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$field = $pf . $field;

		$qb->andWhere($expr->eq($field, $qb->createNamedParameter($value)));
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 */
	protected function limitToDBFieldEmpty(IQueryBuilder &$qb, string $field) {
		$expr = $qb->expr();
		$pf = ($qb->getType() === QueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$field = $pf . $field;

		$qb->andWhere($expr->eq($field, $qb->createNamedParameter('')));
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param DateTime $date
	 */
	protected function limitToDBFieldDateTime(IQueryBuilder &$qb, string $field, DateTime $date) {
		$expr = $qb->expr();
		$pf = ($qb->getType() === QueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$field = $pf . $field;

		$orX = $expr->orX();
		$orX->add($expr->lte($field, $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE)));
		$orX->add($expr->isNull($field));
		$qb->andWhere($orX);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param array $values
	 */
	protected function limitToDBFieldArray(IQueryBuilder &$qb, string $field, array $values) {
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
	protected function searchInDBField(IQueryBuilder &$qb, string $field, string $value) {
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
		   ->selectAlias('ca.type', 'cacheactor_type')
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
		$actor->importFromDatabase($new);

		if ($actor->getType() !== Person::TYPE) {
			throw new InvalidResourceException();
		}

		return $actor;
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $fieldDocumentId
	 */
	protected function leftJoinCacheDocuments(IQueryBuilder &$qb, string $fieldDocumentId) {
		if ($qb->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $qb->expr();
		$pf = $this->defaultSelectAlias;

//		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->selectAlias('cd.id', 'cachedocument_id')
		   ->selectAlias('cd.type', 'cachedocument_type')
		   ->selectAlias('cd.mime_type', 'cachedocument_mime_type')
		   ->selectAlias('cd.media_type', 'cachedocument_media_type')
		   ->selectAlias('cd.url', 'cachedocument_url')
		   ->selectAlias('cd.local_copy', 'cachedocument_local_copy')
		   ->selectAlias('cd.caching', 'cachedocument_caching')
		   ->selectAlias('cd.public', 'cachedocument_public')
		   ->selectAlias('cd.error', 'cachedocument_error')
		   ->selectAlias('ca.creation', 'cachedocument_creation')
		   ->leftJoin(
			   $this->defaultSelectAlias, CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, 'cd',
			   $expr->eq($pf . '.' . $fieldDocumentId, 'cd.id')
		   );
	}


	/**
	 * @param array $data
	 *
	 * @return Document
	 * @throws InvalidResourceException
	 */
	protected function parseCacheDocumentsLeftJoin(array $data): Document {
		$new = [];

		foreach ($data as $k => $v) {
			if (substr($k, 0, 14) === 'cachedocument_') {
				$new[substr($k, 14)] = $v;
			}
		}
		$document = new Document();

		$document->importFromDatabase($new);

		if ($document->getType() !== Image::TYPE) {
			throw new InvalidResourceException();
		}

		return $document;
	}

}



