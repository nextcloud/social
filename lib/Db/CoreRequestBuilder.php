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
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Activity\Follow;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Actor\Person;
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
	const TABLE_SERVER_HASHTAGS = 'social_server_hashtags';
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

	/** @var string */
	private $viewerId = '';


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
	 * @return string
	 */
	public function getViewerId(): string {
		return $this->viewerId;
	}

	/**
	 * @param string $viewerId
	 */
	public function setViewerId(string $viewerId) {
		$this->viewerId = $viewerId;
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
		$this->limitToDBField($qb, 'id', $id, false);
	}


	/**
	 * Limit the request to the UserId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $userId
	 */
	protected function limitToUserId(IQueryBuilder &$qb, string $userId) {
		$this->limitToDBField($qb, 'user_id', $userId, false);
	}


	/**
	 * Limit the request to the Preferred Username
	 *
	 * @param IQueryBuilder $qb
	 * @param string $username
	 */
	protected function limitToPreferredUsername(IQueryBuilder &$qb, string $username) {
		$this->limitToDBField($qb, 'preferred_username', $username, false);
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
	 * @param string $hashtag
	 */
	protected function limitToHashtag(IQueryBuilder &$qb, string $hashtag) {
		$this->limitToDBField($qb, 'hashtag', $hashtag);
	}


	/**
	 * Limit the request to the ActorId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $actorId
	 * @param string $alias
	 */
	protected function limitToActorId(IQueryBuilder &$qb, string $actorId, string $alias = '') {
		$this->limitToDBField($qb, 'actor_id', $actorId, false, $alias);
	}


	/**
	 * Limit the request to the FollowId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $followId
	 */
	protected function limitToFollowId(IQueryBuilder &$qb, string $followId) {
		$this->limitToDBField($qb, 'follow_id', $followId, false);
	}


	/**
	 * Limit the request to the FollowId
	 *
	 * @param IQueryBuilder $qb
	 * @param bool $accepted
	 * @param string $alias
	 */
	protected function limitToAccepted(IQueryBuilder &$qb, bool $accepted, string $alias = '') {
		$this->limitToDBField($qb, 'accepted', ($accepted) ? '1' : '0', true, $alias);
	}


	/**
	 * Limit the request to the ServiceId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $objectId
	 */
	protected function limitToObjectId(IQueryBuilder &$qb, string $objectId) {
		$this->limitToDBField($qb, 'object_id', $objectId, false);
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

		$this->limitToDBFieldDateTime($qb, 'creation', $date, true);
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

		$this->limitToDBFieldDateTime($qb, 'caching', $date, true);
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
	 * Limit the request to the url
	 *
	 * @param IQueryBuilder $qb
	 * @param string $actorId
	 */
	protected function limitToAttributedTo(IQueryBuilder &$qb, string $actorId) {
		$this->limitToDBField($qb, 'attributed_to', $actorId, false);
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
	 * @param int $since
	 * @param int $limit
	 */
	protected function limitPaginate(IQueryBuilder &$qb, int $since = 0, int $limit = 5) {
		if ($since > 0) {
			$dTime = new \DateTime();
			$dTime->setTimestamp($since);
			$this->limitToDBFieldDateTime($qb, 'published_time', $dTime);
		}

		$qb->setMaxResults($limit);
		$pf = $this->defaultSelectAlias;
		$qb->orderBy($pf . '.published_time', 'desc');
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
		$expr = $this->exprLimitToDBField($qb, $field, $value, $cs, $alias);
		$qb->andWhere($expr);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param string $value
	 * @param bool $cs
	 * @param string $alias
	 *
	 * @return string
	 */
	protected function exprLimitToDBField(
		IQueryBuilder &$qb, string $field, string $value, bool $cs = true, string $alias = ''
	): string {
		$expr = $qb->expr();

		$pf = '';
		if ($qb->getType() === QueryBuilder::SELECT) {
			$pf = (($alias === '') ? $this->defaultSelectAlias : $alias) . '.';
		}
		$field = $pf . $field;

		if ($cs) {
			return $expr->eq($field, $qb->createNamedParameter($value));
		} else {
			$func = $qb->func();

			return $expr->eq($func->lower($field), $func->lower($qb->createNamedParameter($value)));
		}
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param int $value
	 * @param string $alias
	 */
	protected function limitToDBFieldInt(
		IQueryBuilder &$qb, string $field, int $value, string $alias = ''
	) {
		$expr = $this->exprLimitToDBFieldInt($qb, $field, $value, $alias);
		$qb->andWhere($expr);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param int $value
	 * @param string $alias
	 *
	 * @return string
	 */
	protected function exprLimitToDBFieldInt(
		IQueryBuilder &$qb, string $field, int $value, string $alias = ''
	): string {
		$expr = $qb->expr();

		$pf = '';
		if ($qb->getType() === QueryBuilder::SELECT) {
			$pf = (($alias === '') ? $this->defaultSelectAlias : $alias) . '.';
		}
		$field = $pf . $field;


		return $expr->eq($field, $qb->createNamedParameter($value));
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
	 * @param bool $orNull
	 */
	protected function limitToDBFieldDateTime(
		IQueryBuilder &$qb, string $field, DateTime $date, bool $orNull = false
	) {
		$expr = $qb->expr();
		$pf = ($qb->getType() === QueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$field = $pf . $field;

		$orX = $expr->orX();
		$orX->add($expr->lte($field, $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE)));

		if ($orNull === true) {
			$orX->add($expr->isNull($field));
		}
		$qb->andWhere($orX);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param int $timestamp
	 * @param string $field
	 */
	protected function limitToSince(IQueryBuilder $qb, int $timestamp, string $field) {
		$dTime = new \DateTime();
		$dTime->setTimestamp($timestamp);

		$expr = $qb->expr();
		$pf = ($qb->getType() === QueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$field = $pf . $field;

		$orX = $expr->orX();
		$orX->add($expr->gte($field, $qb->createNamedParameter($dTime, IQueryBuilder::PARAM_DATE)));

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
		$func = $qb->func();

		$pf = $this->defaultSelectAlias;

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
		   ->selectAlias('ca.local', 'cacheactor_local')
		   ->leftJoin(
			   $this->defaultSelectAlias, CoreRequestBuilder::TABLE_CACHE_ACTORS, 'ca',
			   $expr->eq($func->lower($pf . '.' . $fieldActorId), $func->lower('ca.id'))
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
		$func = $qb->func();
		$pf = $this->defaultSelectAlias;

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
			   $expr->eq($func->lower($pf . '.' . $fieldDocumentId), $func->lower('cd.id'))
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


	/**
	 * @param IQueryBuilder $qb
	 * @param string $fieldActorId
	 * @param bool $asFollower
	 * @param string $prefix
	 * @param string $pf
	 */
	protected function leftJoinFollowAsViewer(
		IQueryBuilder &$qb, string $fieldActorId, bool $asFollower = true,
		string $prefix = 'follow', string $pf = ''
	) {
		if ($qb->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$viewerId = $this->getViewerId();
		if ($viewerId === '') {
			return;
		}

		$expr = $qb->expr();
		$func = $qb->func();
		if ($pf === '') {
			$pf = $this->defaultSelectAlias;
		}

		$andX = $expr->andX();
		$andX->add($this->exprLimitToDBFieldInt($qb, 'accepted', 1, $prefix . '_f'));
		if ($asFollower === true) {
			$andX->add(
				$expr->eq(
					$func->lower($pf . '.' . $fieldActorId), $func->lower($prefix . '_f.object_id')
				)
			);
			$andX->add(
				$expr->eq(
					$func->lower($prefix . '_f.actor_id'),
					$func->lower($qb->createNamedParameter($viewerId))
				)
			);
		} else {
			$andX->add(
				$expr->eq(
					$func->lower($pf . '.' . $fieldActorId), $func->lower($prefix . '_f.actor_id')
				)
			);
			$andX->add(
				$expr->eq(
					$func->lower($prefix . '_f.object_id'),
					$func->lower($qb->createNamedParameter($viewerId))
				)
			);
		}

		$qb->selectAlias($prefix . '_f.id', $prefix . '_id')
		   ->selectAlias($prefix . '_f.type', $prefix . '_type')
		   ->selectAlias($prefix . '_f.actor_id', $prefix . '_actor_id')
		   ->selectAlias($prefix . '_f.object_id', $prefix . '_object_id')
		   ->selectAlias($prefix . '_f.follow_id', $prefix . '_follow_id')
		   ->selectAlias($prefix . '_f.creation', $prefix . '_creation')
		   ->leftJoin(
			   $this->defaultSelectAlias, CoreRequestBuilder::TABLE_SERVER_FOLLOWS, $prefix . '_f',
			   $andX
		   );
	}


	/**
	 * @param array $data
	 * @param string $prefix
	 *
	 * @return Follow
	 * @throws InvalidResourceException
	 */
	protected function parseFollowLeftJoin(array $data, string $prefix): Follow {
		$new = [];

		$length = strlen($prefix) + 1;
		foreach ($data as $k => $v) {
			if (substr($k, 0, $length) === $prefix . '_') {
				$new[substr($k, $length)] = $v;
			}
		}

		$follow = new Follow();
		$follow->importFromDatabase($new);

		if ($follow->getType() !== Follow::TYPE) {
			throw new InvalidResourceException();
		}

		return $follow;
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $fieldActorId
	 * @param string $pf
	 */
	protected function leftJoinDetails(
		IQueryBuilder $qb, string $fieldActorId = 'id', string $pf = ''
	) {
		$this->leftJoinFollowAsViewer($qb, $fieldActorId, true, 'as_follower', $pf);
		$this->leftJoinFollowAsViewer($qb, $fieldActorId, false, 'as_followed', $pf);
	}


	/**
	 * @param Person $actor
	 * @param array $data
	 */
	protected function assignDetails(Person $actor, array $data) {
		if ($this->getViewerId() !== '') {

			try {
				$this->parseFollowLeftJoin($data, 'as_follower');
				$actor->addDetailBool('following', true);
			} catch (InvalidResourceException $e) {
				$actor->addDetailBool('following', false);
			}

			try {
				$this->parseFollowLeftJoin($data, 'as_followed');
				$actor->addDetailBool('followed', true);
			} catch (InvalidResourceException $e) {
				$actor->addDetailBool('followed', false);
			}

			$actor->setCompleteDetails(true);
		}
	}


	/**
	 * this just empty all tables from the app.
	 */
	public function emptyAll() {
		$tables = [
			self::TABLE_REQUEST_QUEUE,
			self::TABLE_SERVER_ACTORS,
			self::TABLE_SERVER_NOTES,
			self::TABLE_SERVER_FOLLOWS,
			self::TABLE_CACHE_ACTORS,
			self::TABLE_CACHE_DOCUMENTS
		];

		foreach ($tables as $table) {
			$qb = $this->dbConnection->getQueryBuilder();
			$qb->delete($table);

			$qb->execute();
		}
	}

}

