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
use OC;
use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Class CoreRequestBuilder
 *
 * @package OCA\Social\Db
 */
class CoreRequestBuilder {
	public const TABLE_ACTIONS = 'social_action';
	public const TABLE_ACTORS = 'social_actor';
	public const TABLE_CACHE_ACTORS = 'social_cache_actor';
	public const TABLE_CACHE_DOCUMENTS = 'social_cache_doc';
	public const TABLE_CLIENT = 'social_client';
	public const TABLE_FOLLOWS = 'social_follow';
	public const TABLE_HASHTAGS = 'social_hashtag';
	public const TABLE_INSTANCE = 'social_instance';
	public const TABLE_NOTIFICATION = 'social_notif';
	public const TABLE_REQUEST_QUEUE = 'social_req_queue';
	public const TABLE_STREAM = 'social_stream';
	public const TABLE_STREAM_ACTIONS = 'social_stream_act';
	public const TABLE_STREAM_DEST = 'social_stream_dest';
	public const TABLE_STREAM_QUEUE = 'social_stream_queue';
	public const TABLE_STREAM_TAGS = 'social_stream_tag';

	public static array $tables = [
		self::TABLE_ACTIONS => [
			'id_prim',
			'id',
			'type',
			'actor_id',
			'actor_id_prim',
			'object_id',
			'object_id_prim',
			'creation'
		],
		self::TABLE_ACTORS => [
			'id_prim',
			'id',
			'user_id',
			'preferred_username',
			'name',
			'summary',
			'public_key',
			'private_key',
			'avatar_version',
			'creation'
		],
		self::TABLE_CACHE_ACTORS => [
			'id_prim',
			'id',
			'type',
			'account',
			'local',
			'following',
			'followers',
			'inbox',
			'outbox',
			'featured',
			'url',
			'preferred_username',
			'name',
			'icon_id',
			'summary',
			'public_key',
			'source',
			'details',
			'details_update',
			'creation'
		],
		self::TABLE_CACHE_DOCUMENTS => [
			'nid',
			'id_prim',
			'id',
			'type',
			'account',
			'parent_id',
			'media_type',
			'mime_type',
			'url',
			'local_copy',
			'resized_copy',
			'meta',
			'blurhash',
			'description',
			'public',
			'error',
			'creation',
			'caching'
		],
		self::TABLE_CLIENT => [
			'id',
			'app_name',
			'app_website',
			'app_redirect_uris',
			'app_client_id',
			'app_client_secret',
			'app_scopes',
			'auth_scopes',
			'auth_account',
			'auth_user_id',
			'auth_code',
			'token',
			'last_update',
			'creation'
		],
		self::TABLE_FOLLOWS => [
			'id_prim',
			'id',
			'type',
			'actor_id',
			'actor_id_prim',
			'object_id',
			'object_id_prim',
			'follow_id',
			'follow_id_prim',
			'accepted',
			'creation'
		],
		self::TABLE_HASHTAGS => [
			'hashtag',
			'trend'
		],
		self::TABLE_INSTANCE => [
			'uri',
			'local',
			'title',
			'version',
			'short_description',
			'description',
			'email',
			'urls',
			'stats',
			'usage',
			'image',
			'languages',
			'contact',
			'account_prim',
			'creation'
		],
		self::TABLE_REQUEST_QUEUE => [
			'id',
			'token',
			'author',
			'activity',
			'instance',
			'priority',
			'status',
			'tries',
			'last'
		],
		self::TABLE_STREAM => [
			'nid',
			'id',
			'id_prim',
			'visibility',
			'type',
			'subtype',
			'to',
			'to_array',
			'cc',
			'bcc',
			'content',
			'summary',
			'published',
			'published_time',
			'attributed_to',
			'attributed_to_prim',
			'in_reply_to',
			'in_reply_to_prim',
			'activity_id',
			'object_id',
			'object_id_prim',
			'hashtags',
			'details',
			'source',
			'instances',
			'attachments',
			'cache',
			'creation',
			'local',
			'filter_duplicate'
		],
		self::TABLE_STREAM_ACTIONS => [
			'id',
			'actor_id',
			'actor_id_prim',
			'stream_id',
			'stream_id_prim',
			'liked',
			'boosted',
			'replied',
			'values'
		],
		self::TABLE_STREAM_DEST => [
			'stream_id',
			'actor_id',
			'type',
			'subtype'
		],
		self::TABLE_STREAM_QUEUE => [
			'id',
			'token',
			'stream_id',
			'type',
			'status',
			'tries',
			'last'
		],
		self::TABLE_STREAM_TAGS => [
			'stream_id',
			'hashtag'
		],
	];

	protected LoggerInterface $logger;
	protected IURLGenerator $urlGenerator;
	protected IDBConnection $dbConnection;
	protected ConfigService $configService;
	protected MiscService $miscService;
	protected ?Person $viewer = null;
	protected ?string $defaultSelectAlias = null;

	public function __construct(
		IDBConnection $connection,
		LoggerInterface $logger,
		IURLGenerator $urlGenerator,
		ConfigService $configService,
		MiscService $miscService
	) {
		$this->dbConnection = $connection;
		$this->logger = $logger;
		$this->urlGenerator = $urlGenerator;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @return SocialQueryBuilder
	 */
	public function getQueryBuilder(): SocialQueryBuilder {
		$qb = new SocialQueryBuilder(
			$this->dbConnection,
			OC::$server->get(\OC\SystemConfig::class),
			$this->logger,
			$this->urlGenerator
		);

		if ($this->viewer !== null) {
			$qb->setViewer($this->viewer);
		}

		return $qb;
	}


	/**
	 * @return IDBConnection
	 */
	public function getConnection(): IDBConnection {
		return $this->dbConnection;
	}


	/**
	 * @param Person $viewer
	 */
	public function setViewer(Person $viewer) {
		$this->viewer = $viewer;
	}


	/**
	 * Limit the request to the Id
	 *
	 * @param IQueryBuilder $qb
	 * @param int $id
	 *
	 * @deprecated
	 */
	protected function limitToId(IQueryBuilder &$qb, int $id) {
		$this->limitToDBFieldInt($qb, 'id', $id);
	}


	/**
	 * Limit the request to the Id (string)
	 *
	 * @param IQueryBuilder $qb
	 * @param string $id
	 *
	 * @deprecated
	 *
	 */
	protected function limitToIdString(IQueryBuilder &$qb, string $id) {
		$this->limitToDBField($qb, 'id', $id, false);
	}


	/**
	 * Limit the request to the UserId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $userId
	 *
	 * @deprecated
	 *
	 */
	protected function limitToUserId(IQueryBuilder &$qb, string $userId) {
		$this->limitToDBField($qb, 'user_id', $userId, false);
	}


	/**
	 * Limit the request to the ActivityId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $activityId
	 */
	protected function limitToActivityId(IQueryBuilder &$qb, string $activityId) {
		$this->limitToDBField($qb, 'activity_id', $activityId, false);
	}


	/**
	 * Limit the request to the Id (string)
	 *
	 * @param IQueryBuilder $qb
	 * @param string $id
	 *
	 * @deprecated
	 */
	protected function limitToInReplyTo(IQueryBuilder &$qb, string $id) {
		$this->limitToDBField($qb, 'in_reply_to', $id, false);
	}


	/**
	 * Limit the request to the StreamId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $streamId
	 */
	protected function limitToStreamId(IQueryBuilder &$qb, string $streamId) {
		$this->limitToDBField($qb, 'stream_id', $streamId, false);
	}


	/**
	 * Limit the request to the Type
	 *
	 * @param IQueryBuilder $qb
	 * @param string $type
	 */
	protected function limitToType(IQueryBuilder &$qb, string $type) {
		$this->limitToDBField($qb, 'type', $type);
	}


	/**
	 * Limit the request to the sub-type
	 *
	 * @param IQueryBuilder $qb
	 * @param string $subType
	 */
	protected function limitToSubType(IQueryBuilder &$qb, string $subType) {
		$this->limitToDBField($qb, 'subtype', $subType);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $type
	 */
	protected function filterType(IQueryBuilder $qb, string $type) {
		$this->filterDBField($qb, 'type', $type);
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
		$dbConn = $this->getConnection();
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
	 * Limit the results to a given number
	 *
	 * @param IQueryBuilder $qb
	 * @param int $limit
	 */
	protected function limitResults(IQueryBuilder $qb, int $limit) {
		$qb->setMaxResults($limit);
	}


	/**
	 * Limit the request to the ActorId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $hashtag
	 */
	protected function limitToHashtag(IQueryBuilder &$qb, string $hashtag) {
		$this->limitToDBField($qb, 'hashtag', $hashtag, false);
	}


	/**
	 * Limit the request to the ActorId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $hashtag
	 * @param bool $all
	 */
	protected function searchInHashtag(IQueryBuilder &$qb, string $hashtag, bool $all = false) {
		$dbConn = $this->getConnection();
		$this->searchInDBField(
			$qb, 'hashtag', (($all) ? '%' : '') . $dbConn->escapeLikeParameter($hashtag) . '%'
		);
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
		$dbConn = $this->getConnection();
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
	 * Limit the request to the parent_id
	 *
	 * @param IQueryBuilder $qb
	 * @param string $parentId
	 */
	protected function limitToParentId(IQueryBuilder &$qb, string $parentId) {
		$this->limitToDBField($qb, 'parent_id', $parentId);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param int $since
	 * @param int $limit
	 *
	 * @throws DateTimeException
	 * @deprecated
	 */
	protected function limitPaginate(IQueryBuilder &$qb, int $since = 0, int $limit = 5) {
		try {
			if ($since > 0) {
				$dTime = new DateTime();
				$dTime->setTimestamp($since);
				$this->limitToDBFieldDateTime($qb, 'published_time', $dTime);
			}
		} catch (Exception $e) {
			throw new DateTimeException();
		}

		$qb->setMaxResults($limit);
		$pf = $this->defaultSelectAlias;
		$qb->orderBy($pf . '.published_time', 'desc');
	}

	//
	//

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
		$expr = $this->exprLimitToDBField($qb, $field, $value, true, $cs, $alias);
		$qb->andWhere($expr);
	}

	protected function filterDBField(
		IQueryBuilder &$qb, string $field, string $value, bool $cs = true, string $alias = ''
	) {
		$expr = $this->exprLimitToDBField($qb, $field, $value, false, $cs, $alias);
		$qb->andWhere($expr);
	}

	protected function exprLimitToDBField(
		IQueryBuilder &$qb, string $field, string $value, bool $eq = true, bool $cs = true,
		string $alias = ''
	): string {
		$expr = $qb->expr();

		$pf = '';
		if ($qb->getType() === QueryBuilder::SELECT) {
			$pf = (($alias === '') ? $this->defaultSelectAlias : $alias) . '.';
		}
		$field = $pf . $field;

		$comp = 'eq';
		if (!$eq) {
			$comp = 'neq';
		}

		if ($cs) {
			return $expr->$comp($field, $qb->createNamedParameter($value));
		} else {
			$func = $qb->func();

			return $expr->$comp(
				$func->lower($field), $func->lower($qb->createNamedParameter($value))
			);
		}
	}

	protected function limitToDBFieldInt(
		IQueryBuilder &$qb, string $field, int $value, string $alias = ''
	): void {
		$expr = $this->exprLimitToDBFieldInt($qb, $field, $value, $alias);
		$qb->andWhere($expr);
	}

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
	 *
	 * @throws DateTimeException
	 */
	protected function limitToSince(IQueryBuilder $qb, int $timestamp, string $field) {
		try {
			$dTime = new DateTime();
		} catch (Exception $e) {
			throw new DateTimeException();
		}

		$dTime->setTimestamp($timestamp);

		$expr = $qb->expr();
		$pf = ($qb->getType() === QueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$field = $pf . $field;

		$orX = $expr->orX();
		$orX->add($expr->gte($field, $qb->createNamedParameter($dTime, IQueryBuilder::PARAM_DATE)));

		$qb->andWhere($orX);
	}


	protected function limitToDBFieldArray(IQueryBuilder &$qb, string $field, array $values): void {
		$expr = $qb->expr();
		$pf = ($qb->getType() === QueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$field = $pf . $field;

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
	 * @param Person $author
	 * @param string $alias
	 *
	 * @deprecated - use SocialCrossQueryBuilder:leftJoinCacheActor
	 */
	protected function leftJoinCacheActors(
		IQueryBuilder &$qb, string $fieldActorId, Person $author = null, string $alias = ''
	) {
		if ($qb->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $qb->expr();
		$func = $qb->func();

		$pf = ($alias === '') ? $this->defaultSelectAlias : $alias;

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
		   ->selectAlias('ca.local', 'cacheactor_local');

		$orX = $expr->orX();
		$orX->add($expr->eq($func->lower($pf . '.' . $fieldActorId), $func->lower('ca.id')));
		if ($author !== null) {
			$andX = $expr->andX();
			$andX->add(
				$this->exprLimitToDBField($qb, 'attributed_to', $author->getId(), true, false, 's')
			);
			$andX->add(
				$expr->eq(
					$func->lower($this->defaultSelectAlias . '.attributed_to'),
					$func->lower('ca.id')
				)
			);
			$orX->add($andX);
		}

		$qb->leftJoin(
			$this->defaultSelectAlias, CoreRequestBuilder::TABLE_CACHE_ACTORS, 'ca', $orX
		);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $fieldActorId
	 * @param string $alias
	 */
	protected function leftJoinAccounts(IQueryBuilder &$qb, string $fieldActorId, string $alias = ''
	) {
		if ($qb->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $qb->expr();
		$func = $qb->func();

		$pf = ($alias === '') ? $this->defaultSelectAlias : $alias;

		$qb->selectAlias('lja.id', 'accounts_id')
		   ->selectAlias('lja.user_id', 'accounts_user_id')
		   ->selectAlias('lja.preferred_username', 'accounts_preferred_username')
		   ->selectAlias('lja.name', 'accounts_name')
		   ->selectAlias('lja.summary', 'accounts_summary')
		   ->selectAlias('lja.public_key', 'accounts_public_key');

		$on = $expr->eq(
			$func->lower($pf . '.' . $fieldActorId),
			$func->lower('lja.id')
		);

		$qb->leftJoin(
			$this->defaultSelectAlias, CoreRequestBuilder::TABLE_ACTORS, 'lja', $on
		);
	}


	/**
	 * @param array $data
	 *
	 * @return Person
	 * @throws InvalidResourceException
	 */
	protected function parseAccountsLeftJoin(array $data): Person {
		$new = [];
		foreach ($data as $k => $v) {
			if (substr($k, 0, 9) === 'accounts_') {
				$new[substr($k, 9)] = $v;
			}
		}

		$actor = new Person();
		$actor->importFromDatabase($new);

		if (!$actor->getUserId()) {
			throw new InvalidResourceException();
		}

		return $actor;
	}


	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @deprecated
	 */
	protected function leftJoinStreamAction(SocialQueryBuilder &$qb) {
		if ($qb->getType() !== QueryBuilder::SELECT || $this->viewer === null) {
			return;
		}

		$pf = $this->defaultSelectAlias;
		$expr = $qb->expr();

		$qb->selectAlias('sa.id', 'streamaction_id')
		   ->selectAlias('sa.actor_id', 'streamaction_actor_id')
		   ->selectAlias('sa.stream_id', 'streamaction_stream_id')
		   ->selectAlias('sa.liked', 'streamaction_liked')
		   ->selectAlias('sa.boosted', 'streamaction_boosted')
		   ->selectAlias('sa.replied', 'streamaction_replied');

		$orX = $expr->orX();
		$orX->add($expr->eq('sa.stream_id_prim', $pf . '.id_prim'));
		$orX->add($expr->eq('sa.stream_id_prim', $pf . '.object_id_prim'));

		$on = $expr->andX();
		$on->add(
			$expr->eq(
				'sa.actor_id_prim', $qb->createNamedParameter($qb->prim($this->viewer->getId()))
			)
		);
		$on->add($orX);

		$qb->leftJoin(
			$this->defaultSelectAlias, CoreRequestBuilder::TABLE_STREAM_ACTIONS, 'sa',
			$on
		);
	}


	/**
	 * @param array $data
	 *
	 * @return StreamAction
	 */
	protected function parseStreamActionsLeftJoin(array $data): StreamAction {
		$new = [];
		foreach ($data as $k => $v) {
			if (substr($k, 0, 13) === 'streamaction_') {
				$new[substr($k, 13)] = $v;
			}
		}

		$action = new StreamAction();
		$action->importFromDatabase($new);
		$action->setDefaultValues(
			[
				'boosted' => false
			]
		);

		return $action;
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $type
	 */
	protected function leftJoinActions(IQueryBuilder &$qb, string $type) {
		if ($qb->getType() !== QueryBuilder::SELECT || $this->viewer === null) {
			return;
		}

		$expr = $qb->expr();
		$func = $qb->func();

		$pf = $this->defaultSelectAlias;

		$qb->selectAlias('a.id', 'action_id')
		   ->selectAlias('a.actor_id', 'action_actor_id')
		   ->selectAlias('a.object_id', 'action_object_id')
		   ->selectAlias('a.type', 'action_type');

		$andX = $expr->andX();
		$andX->add($expr->eq($func->lower($pf . '.id'), $func->lower('a.object_id')));
		$andX->add($expr->eq('a.type', $qb->createNamedParameter($type)));
		$andX->add(
			$expr->eq(
				$func->lower('a.actor_id'),
				$qb->createNamedParameter(strtolower($this->viewer->getId()))
			)
		);

		$qb->leftJoin(
			$this->defaultSelectAlias, CoreRequestBuilder::TABLE_ACTIONS, 'a', $andX
		);
	}


	/**
	 * @param array $data
	 */
	protected function parseActionsLeftJoin(array $data) {
		$new = [];
		foreach ($data as $k => $v) {
			if (substr($k, 0, 7) === 'action_') {
				$new[substr($k, 7)] = $v;
			}
		}

		//		$action = new Action();
		//		$action->importFromDatabase($new);

		//		return $action;
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

		if ($this->viewer === null) {
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
					$func->lower($qb->createNamedParameter($this->viewer->getId()))
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
					$func->lower($qb->createNamedParameter($this->viewer->getId()))
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
		   	$this->defaultSelectAlias, CoreRequestBuilder::TABLE_FOLLOWS, $prefix . '_f',
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
	protected function leftJoinDetails(IQueryBuilder $qb, string $fieldActorId = 'id', string $pf = '') {
		$this->leftJoinFollowAsViewer($qb, $fieldActorId, true, 'as_follower', $pf);
		$this->leftJoinFollowAsViewer($qb, $fieldActorId, false, 'as_followed', $pf);
	}


	/**
	 * @param Person $actor
	 * @param array $data
	 */
	protected function assignDetails(Person $actor, array $data) {
		if ($this->viewer === null) {
			return;
		}

		try {
			$this->parseFollowLeftJoin($data, 'as_follower');
			$actor->setDetailBool('following', true);
		} catch (InvalidResourceException $e) {
			$actor->setDetailBool('following', false);
		}

		try {
			$this->parseFollowLeftJoin($data, 'as_followed');
			$actor->setDetailBool('followed', true);
		} catch (InvalidResourceException $e) {
			$actor->setDetailBool('followed', false);
		}

		$actor->setCompleteDetails(true);
	}


	/**
	 * this just empty all tables from the app.
	 */
	public function emptyAll() {
		$schema = new SchemaWrapper(Server::get(Connection::class));
		foreach (array_keys(self::$tables) as $table) {
			if ($schema->hasTable($table)) {
				$qb = $this->getQueryBuilder();
				$qb->delete($table);
				$qb->execute();
			}
		}
	}


	/**
	 * this just empty all tables from the app.
	 */
	public function uninstallSocialTables() {
		$schema = new SchemaWrapper(Server::get(Connection::class));
		foreach (array_keys(self::$tables) as $table) {
			if ($schema->hasTable($table)) {
				$schema->dropTable($table);
			}
		}

		$schema->performDropTableCalls();
	}


	/**
	 *
	 */
	public function uninstallFromMigrations() {
		$qb = $this->getQueryBuilder();
		$qb->delete('migrations');
		$qb->where($this->exprLimitToDBField($qb, 'app', 'social', true, true));

		$qb->execute();
	}

	/**
	 *
	 */
	public function uninstallFromJobs() {
		$qb = $this->getQueryBuilder();
		$qb->delete('jobs');
		$qb->where($this->exprLimitToDBField($qb, 'class', 'OCA\Social\Cron\Cache', true, true));
		$qb->execute();

		$qb = $this->getQueryBuilder();
		$qb->delete('jobs');
		$qb->where($this->exprLimitToDBField($qb, 'class', 'OCA\Social\Cron\Queue', true, true));
		$qb->execute();
	}
}
