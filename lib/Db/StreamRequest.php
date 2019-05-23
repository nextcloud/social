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


use daita\MySmallPhpTools\Model\Cache;
use DateTime;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Exception;
use OCA\Social\Exceptions\DateTimeException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;


/**
 * Class StreamRequest
 *
 * @package OCA\Social\Db
 */
class StreamRequest extends StreamRequestBuilder {


	/**
	 * StreamRequest constructor.
	 *
	 * @param IDBConnection $connection
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IDBConnection $connection, ConfigService $configService, MiscService $miscService
	) {
		parent::__construct($connection, $configService, $miscService);
	}


	/**
	 * @param Stream $stream
	 */
	public function save(Stream $stream) {
		$qb = $this->saveStream($stream);

		if ($stream->getType() === Note::TYPE) {
			/** @var Note $stream */
			$qb->setValue(
				'hashtags', $qb->createNamedParameter(json_encode($stream->getHashtags()))
			)
			   ->setValue(
				   'attachments', $qb->createNamedParameter(
				   json_encode($stream->getAttachments(), JSON_UNESCAPED_SLASHES)
			   )
			   );
		}

		$qb->execute();
	}


	/**
	 * @param Stream $stream
	 * @param Cache $cache
	 */
	public function updateCache(Stream $stream, Cache $cache) {
		$qb = $this->getStreamUpdateSql();
		$qb->set('cache', $qb->createNamedParameter(json_encode($cache, JSON_UNESCAPED_SLASHES)));

		$this->limitToIdString($qb, $stream->getId());

		try {
			$qb->execute();
		} catch (UniqueConstraintViolationException $e) {
		}
	}


	/**
	 * @param string $id
	 * @param bool $asViewer
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	public function getStreamById(string $id, bool $asViewer = false): Stream {
		if ($id === '') {
			throw new StreamNotFoundException();
		};

		$qb = $this->getStreamSelectSql();
		$this->limitToIdString($qb, $id);
		$this->leftJoinCacheActors($qb, 'attributed_to');

		if ($asViewer) {
			$this->limitToViewer($qb);
			$this->leftJoinStreamAction($qb);
		}

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new StreamNotFoundException(
				'Stream (ById) not found - ' . $id . ' (asViewer: ' . $asViewer . ')'
			);
		}

		try {
			$stream = $this->parseStreamSelectSql($data);
		} catch (Exception $e) {
			throw new StreamNotFoundException('Malformed Stream');
		}

		return $stream;
	}


	/**
	 * @param string $id
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 * @throws Exception
	 */
	public function getStreamByActivityId(string $id): Stream {
		if ($id === '') {
			throw new StreamNotFoundException();
		};

		$qb = $this->getStreamSelectSql();
		$this->limitToActivityId($qb, $id);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new StreamNotFoundException('Stream (ByActivityId) not found - ' . $id);
		}

		return $this->parseStreamSelectSql($data);
	}


	/**
	 * @param Person $actor
	 * @param string $type
	 *
	 * @param string $objectId
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	public function getStreamByObjectId(Person $actor, string $type, string $objectId): Stream {
		if ($objectId === '') {
			throw new StreamNotFoundException('missing objectId');
		};

		$qb = $this->getStreamSelectSql();
		$this->limitToObjectId($qb, $objectId);
		$this->limitToType($qb, $type);
		$this->limitToAttributedTo($qb, $actor->getId());

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new StreamNotFoundException(
				'StreamByObjectId not found - ' . $actor->getId() . ' - ' . $type . ' - '
				. $objectId
			);
		}

		return $this->parseStreamSelectSql($data);
	}


	/**
	 * @param string $actorId
	 *
	 * @return int
	 */
	public function countNotesFromActorId(string $actorId): int {
		$qb = $this->countNotesSelectSql();
		$this->limitToAttributedTo($qb, $actorId);
		$this->limitToType($qb, Note::TYPE);
		$this->limitToRecipient($qb, ACore::CONTEXT_PUBLIC);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}


	/**
	 * Should returns:
	 *  * Own posts,
	 *  * Followed accounts
	 *
	 * @param Person $actor
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Stream[]
	 * @throws Exception
	 */
	public function getTimelineHome(Person $actor, int $since = 0, int $limit = 5): array {
		$qb = $this->getStreamSelectSql();

		$this->joinFollowing($qb, $actor);
		$this->limitPaginate($qb, $since, $limit);
		$this->filterHiddenOnTimeline($qb);

		$this->leftJoinCacheActors($qb, 'attributed_to');
		$this->leftJoinStreamAction($qb);

		$streams = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			try {
				$streams[] = $this->parseStreamSelectSql($data);
			} catch (Exception $e) {
			}
		}
		$cursor->closeCursor();

		return $streams;
	}


	/**
	 * Should returns:
	 *  * Public/Unlisted/Followers-only post where current $actor is tagged,
	 *  - Events: (not yet)
	 *    - people liking or re-posting your posts (not yet)
	 *    - someone wants to follow you (not yet)
	 *    - someone is following you (not yet)
	 *
	 * @param Person $actor
	 * @param int $since
	 * @param int $limit
	 *
	 * @return array
	 * @throws Exception
	 */
	public function getTimelineNotifications(Person $actor, int $since = 0, int $limit = 5): array {
		$qb = $this->getStreamSelectSql();

		$this->limitPaginate($qb, $since, $limit);
		$this->limitToRecipient($qb, $actor->getId(), false);
		$this->limitToType($qb, SocialAppNotification::TYPE);

		$this->leftJoinCacheActors($qb, 'attributed_to');
		$this->leftJoinStreamAction($qb);

		$streams = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			try {
				$streams[] = $this->parseStreamSelectSql($data);
			} catch (Exception $e) {
			}
		}
		$cursor->closeCursor();

		return $streams;
	}


	/**
	 * Should returns:
	 *  * public message from actorId.
	 *  - to followers-only if follower is logged. (not yet (check ?))
	 *
	 * @param string $actorId
	 * @param int $since
	 * @param int $limit
	 *
	 * @return array
	 * @throws Exception
	 */
	public function getTimelineAccount(string $actorId, int $since = 0, int $limit = 5): array {
		$qb = $this->getStreamSelectSql();
		$this->limitPaginate($qb, $since, $limit);

		$this->limitToAttributedTo($qb, $actorId);
		$this->limitToRecipient($qb, ACore::CONTEXT_PUBLIC);

		$this->leftJoinCacheActors($qb, 'attributed_to');
		$this->leftJoinStreamAction($qb);

		$streams = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			try {
				$streams[] = $this->parseStreamSelectSql($data);
			} catch (Exception $e) {
			}
		}
		$cursor->closeCursor();

		return $streams;
	}


	/**
	 * Should returns:
	 *  * Private message.
	 *  - group messages. (not yet)
	 *
	 * @param Person $actor
	 * @param int $since
	 * @param int $limit
	 *
	 * @return array
	 * @throws Exception
	 */
	public function getTimelineDirect(Person $actor, int $since = 0, int $limit = 5): array {
		$qb = $this->getStreamSelectSql();
		$this->limitPaginate($qb, $since, $limit);

		$this->limitToRecipient($qb, $actor->getId(), true);
		$this->filterRecipient($qb, ACore::CONTEXT_PUBLIC);
		$this->filterRecipient($qb, $actor->getFollowers());
		$this->filterHiddenOnTimeline($qb);
		$this->filterType($qb, SocialAppNotification::TYPE);

		$this->leftJoinCacheActors($qb, 'attributed_to');

		$streams = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			try {
				$streams[] = $this->parseStreamSelectSql($data);
			} catch (Exception $e) {
			}
		}
		$cursor->closeCursor();

		return $streams;
	}


	/**
	 * Should returns:
	 *  * All local public/federated posts
	 *
	 * @param int $since
	 * @param int $limit
	 * @param bool $localOnly
	 *
	 * @return array
	 * @throws Exception
	 */
	public function getTimelineGlobal(int $since = 0, int $limit = 5, bool $localOnly = true
	): array {
		$qb = $this->getStreamSelectSql();
		$this->limitPaginate($qb, $since, $limit);

		if ($localOnly) {
			$this->limitToLocal($qb, true);
		}

		$this->filterHiddenOnTimeline($qb);
		$this->leftJoinCacheActors($qb, 'attributed_to');
		$this->leftJoinStreamAction($qb);

		// TODO: to: = real public, cc: = unlisted !?
		$this->limitToRecipient($qb, ACore::CONTEXT_PUBLIC, true, ['to']);

		$streams = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			try {
				$streams[] = $this->parseStreamSelectSql($data);
			} catch (Exception $e) {
			}
		}
		$cursor->closeCursor();

		return $streams;
	}


	/**
	 * Should returns:
	 *  - All public post related to a tag (not yet)
	 *  - direct message related to a tag (not yet)
	 *  - message to followers related to a tag (not yet)
	 *
	 * @param Person $actor
	 * @param string $hashtag
	 * @param int $since
	 * @param int $limit
	 *
	 * @return array
	 * @throws Exception
	 */
	public function getTimelineTag(Person $actor, string $hashtag, int $since = 0, int $limit = 5
	): array {
		$qb = $this->getStreamSelectSql();

		$on = $this->exprJoinFollowing($qb, $actor);
		$on->add($this->exprLimitToRecipient($qb, ACore::CONTEXT_PUBLIC, false));
		$on->add($this->exprLimitToRecipient($qb, $actor->getId(), true));
		$qb->join($this->defaultSelectAlias, CoreRequestBuilder::TABLE_FOLLOWS, 'f', $on);

		$qb->andWhere($this->exprValueWithinJsonFormat($qb, 'hashtags', '' . $hashtag));

		$this->limitPaginate($qb, $since, $limit);
		$this->filterHiddenOnTimeline($qb);

		$this->leftJoinCacheActors($qb, 'attributed_to');
		$this->leftJoinStreamAction($qb);

		$streams = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$streams[] = $this->parseStreamSelectSql($data);
		}
		$cursor->closeCursor();

		return $streams;
	}


	/**
	 * @param int $since
	 *
	 * @return Stream[]
	 * @throws DateTimeException
	 */
	public function getNoteSince(int $since): array {
		$qb = $this->getStreamSelectSql();
		$this->limitToSince($qb, $since, 'published_time');
		$this->limitToType($qb, Note::TYPE);
		$this->leftJoinStreamAction($qb);

		$streams = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$streams[] = $this->parseStreamSelectSql($data);
		}
		$cursor->closeCursor();

		return $streams;
	}


	/**
	 * @param string $id
	 * @param string $type
	 */
	public function deleteStreamById(string $id, string $type = '') {
		$qb = $this->getStreamDeleteSql();

		$this->limitToIdString($qb, $id);
		if ($type !== '') {
			$this->limitToType($qb, $type);
		}

		$qb->execute();
	}


	/**
	 * @param string $actorId
	 */
	public function deleteByAuthor(string $actorId) {
		$qb = $this->getStreamDeleteSql();
		$this->limitToAttributedTo($qb, $actorId);

		$qb->execute();
	}


	/**
	 * Insert a new Stream in the database.
	 *
	 * @param Stream $stream
	 *
	 * @return IQueryBuilder
	 */
	public function saveStream(Stream $stream): IQueryBuilder {

		try {
			$dTime = new DateTime();
			$dTime->setTimestamp($stream->getPublishedTime());
		} catch (Exception $e) {
		}

		$cache = '[]';
		if ($stream->gotCache()) {
			$cache = json_encode($stream->getCache(), JSON_UNESCAPED_SLASHES);
		}

		$attributedTo = $stream->getAttributedTo();
		if ($attributedTo === '' && $stream->isLocal()) {
			$attributedTo = $stream->getActor()
								   ->getId();
		}

		$qb = $this->getStreamInsertSql();
		$qb->setValue('id', $qb->createNamedParameter($stream->getId()))
		   ->setValue('type', $qb->createNamedParameter($stream->getType()))
		   ->setValue('to', $qb->createNamedParameter($stream->getTo()))
		   ->setValue(
			   'to_array', $qb->createNamedParameter(
			   json_encode($stream->getToArray(), JSON_UNESCAPED_SLASHES)
		   )
		   )
		   ->setValue(
			   'cc', $qb->createNamedParameter(
			   json_encode($stream->getCcArray(), JSON_UNESCAPED_SLASHES)
		   )
		   )
		   ->setValue(
			   'bcc', $qb->createNamedParameter(
			   json_encode($stream->getBccArray()), JSON_UNESCAPED_SLASHES
		   )
		   )
		   ->setValue('content', $qb->createNamedParameter($stream->getContent()))
		   ->setValue('summary', $qb->createNamedParameter($stream->getSummary()))
		   ->setValue('published', $qb->createNamedParameter($stream->getPublished()))
		   ->setValue('attributed_to', $qb->createNamedParameter($attributedTo))
		   ->setValue('in_reply_to', $qb->createNamedParameter($stream->getInReplyTo()))
		   ->setValue('source', $qb->createNamedParameter($stream->getSource()))
		   ->setValue('activity_id', $qb->createNamedParameter($stream->getActivityId()))
		   ->setValue('object_id', $qb->createNamedParameter($stream->getObjectId()))
		   ->setValue('cache', $qb->createNamedParameter($cache))
		   ->setValue(
			   'hidden_on_timeline',
			   $qb->createNamedParameter(($stream->isHiddenOnTimeline()) ? '1' : '0')
		   )
		   ->setValue(
			   'instances', $qb->createNamedParameter(
			   json_encode($stream->getInstancePaths(), JSON_UNESCAPED_SLASHES)
		   )
		   )
		   ->setValue('local', $qb->createNamedParameter(($stream->isLocal()) ? '1' : '0'));

		try {
			$dTime = new DateTime();
			$dTime->setTimestamp($stream->getPublishedTime());
			$qb->setValue(
				'published_time', $qb->createNamedParameter($dTime, IQueryBuilder::PARAM_DATE)
			)
			   ->setValue(
				   'creation',
				   $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			   );
		} catch (Exception $e) {
		}

		$this->generatePrimaryKey($qb, $stream->getId());

		return $qb;
	}

}

