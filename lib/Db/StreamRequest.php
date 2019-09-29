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
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Query\QueryBuilder;
use Exception;
use OCA\Social\Exceptions\DateTimeException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\ILogger;


/**
 * Class StreamRequest
 *
 * @package OCA\Social\Db
 */
class StreamRequest extends StreamRequestBuilder {


	/** @var StreamDestRequest */
	private $streamDestRequest;


	/**
	 * StreamRequest constructor.
	 *
	 * @param IDBConnection $connection
	 * @param ILogger $logger
	 * @param StreamDestRequest $streamDestRequest
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IDBConnection $connection, ILogger $logger, StreamDestRequest $streamDestRequest,
		ConfigService $configService, MiscService $miscService
	) {
		parent::__construct($connection, $logger, $configService, $miscService);

		$this->streamDestRequest = $streamDestRequest;
	}


	/**
	 * @param Stream $stream
	 */
	public function save(Stream $stream) {
		$qb = $this->saveStream($stream);
		if ($stream->getType() === Note::TYPE) {
			/** @var Note $stream */
			$qb->setValue('hashtags', $qb->createNamedParameter(json_encode($stream->getHashtags())))
			   ->setValue(
				   'attachments', $qb->createNamedParameter(
				   json_encode($stream->getAttachments(), JSON_UNESCAPED_SLASHES)
			   )
			   );
		}

		try {
			$qb->execute();

			$this->streamDestRequest->generateStreamDest($stream);
		} catch (UniqueConstraintViolationException $e) {
		}
	}


	/**
	 * @param Stream $stream \
	 *
	 * @return Statement|int
	 */
	public function update(Stream $stream) {
		$qb = $this->getStreamUpdateSql();

		$qb->set('details', $qb->createNamedParameter(json_encode($stream->getDetailsAll())));
		$qb->set(
			'cc', $qb->createNamedParameter(
			json_encode($stream->getCcArray(), JSON_UNESCAPED_SLASHES)
		)
		);
		$qb->limitToIdPrim($qb->prim($stream->getId()));

		// TODO - update StreamDest !???
		return $qb->execute();
	}


	/**
	 * @param Stream $stream
	 * @param Cache $cache
	 */
	public function updateCache(Stream $stream, Cache $cache) {
		$qb = $this->getStreamUpdateSql();
		$qb->set('cache', $qb->createNamedParameter(json_encode($cache, JSON_UNESCAPED_SLASHES)));

		$qb->limitToIdPrim($qb->prim($stream->getId()));

		$qb->execute();
	}


	/**
	 * @param Document $document
	 */
	public function updateAttachments(Document $document) {
		$qb = $this->getStreamSelectSql();
		$qb->limitToIdPrim($qb->prim($document->getParentId()));

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			return;
		}

		$new = $this->updateAttachmentInList($document, $this->getArray('attachments', $data, []));
		$qb = $this->getStreamUpdateSql();
		$qb->set(
			'attachments', $qb->createNamedParameter(json_encode($new, JSON_UNESCAPED_SLASHES))
		);
		$qb->limitToIdPrim($qb->prim($document->getParentId()));

		$qb->execute();
	}

	/**
	 * @param Document $document
	 * @param array $attachments
	 *
	 * @return Document[]
	 */
	private function updateAttachmentInList(Document $document, array $attachments): array {
		$new = [];
		foreach ($attachments as $attachment) {
			$tmp = new Document();
			$tmp->importFromDatabase($attachment);
			if ($tmp->getId() === $document->getId()) {
				$new[] = $document;
			} else {
				$new[] = $tmp;
			}
		}

		return $new;
	}


	/**
	 * @param string $itemId
	 * @param string $to
	 */
	public function updateAttributedTo(string $itemId, string $to) {
		$qb = $this->getStreamUpdateSql();
		$qb->set('attributed_to', $qb->createNamedParameter($to));
		$qb->set('attributed_to_prim', $qb->createNamedParameter($qb->prim($to)));

		$qb->limitToIdPrim($qb->prim($itemId));

		$qb->execute();
	}


	/**
	 * @param string $type
	 *
	 * @return Stream[]
	 */
	public function getAll(string $type = ''): array {
		$qb = $this->getStreamSelectSql();

		if ($type !== '') {
			$qb->limitToType($type);
		}

		return $this->getStreamsFromRequest($qb);
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
		$expr = $qb->expr();

		$qb->limitToIdPrim($qb->prim($id));
		$qb->selectCacheActors('ca');
		$qb->andWhere($expr->eq('s.attributed_to_prim', 'ca.id_prim'));

		if ($asViewer) {
			$qb->limitToViewer('sd', 'f');
			$qb->leftJoinStreamAction('sa');
		}

		try {
			return $this->getStreamFromRequest($qb);
		} catch (ItemUnknownException $e) {
			throw new StreamNotFoundException('Malformed Stream');
		} catch (StreamNotFoundException $e) {
			throw new StreamNotFoundException(
				'Stream (ById) not found - ' . $id . ' (asViewer: ' . $asViewer . ')'
			);
		}
	}


	/**
	 * @param string $id
	 * @param int $since
	 * @param int $limit
	 * @param bool $asViewer
	 *
	 * @return Stream[]
	 * @throws StreamNotFoundException
	 * @throws DateTimeException
	 */
	public function getRepliesByParentId(string $id, int $since = 0, int $limit = 5, bool $asViewer = false
	): array {
		if ($id === '') {
			throw new StreamNotFoundException();
		};

		$qb = $this->getStreamSelectSql();
		$this->limitToInReplyTo($qb, $id);
		$this->limitPaginate($qb, $since, $limit);
		$this->leftJoinCacheActors($qb, 'attributed_to');

		if ($asViewer) {
			$this->limitToViewer($qb);
			$this->leftJoinStreamAction($qb);
		}

		return $this->getStreamsFromRequest($qb);
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

		return $this->getStreamFromRequest($qb);
	}


	/**
	 * @param string $objectId
	 * @param string $type
	 * @param string $subType
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	public function getStreamByObjectId(string $objectId, string $type, string $subType = ''
	): Stream {
		if ($objectId === '') {
			throw new StreamNotFoundException('missing objectId');
		};

		$qb = $this->getStreamSelectSql();
		$this->limitToObjectId($qb, $objectId);
		$qb->limitToType($type);
		$this->limitToSubType($qb, $subType);

		return $this->getStreamFromRequest($qb);
	}


	/**
	 * @param string $actorId
	 *
	 * @return int
	 */
	public function countNotesFromActorId(string $actorId): int {
		$qb = $this->countNotesSelectSql();
		$this->limitToAttributedTo($qb, $actorId);
		$qb->limitToType(Note::TYPE);
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
	 * @throws DateTimeException
	 */
	public function getTimelineHome(Person $actor, int $since = 0, int $limit = 5): array {
		$qb = $this->getStreamSelectSql();
		$expr = $qb->expr();

		$qb->selectCacheActors('ca');
		$qb->limitPaginate($since, $limit);

		$qb->andWhere($qb->exprLimitToDBField('type', SocialAppNotification::TYPE, false));

		$qb->limitToViewer('sd', 'f');
//		$qb->selectDestFollowing('sd', 'f');
//		$qb->andWhere($qb->exprInnerJoinDestFollowing($actor, 'recipient', 'id_prim', 'sd', 'f'));
		//

		$qb->andWhere($expr->eq('f.object_id_prim', 'ca.id_prim'));

		$qb->leftJoinStreamAction('sa');
		$qb->filterDuplicate();

		return $this->getStreamsFromRequest($qb);
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
	 * @return Stream[]
	 * @throws DateTimeException
	 */
	public function getTimelineNotifications(Person $actor, int $since = 0, int $limit = 5): array {
		$qb = $this->getStreamSelectSql();

		$this->limitPaginate($qb, $since, $limit);
		$this->limitToRecipient($qb, $actor->getId(), false);
		$qb->limitToType(SocialAppNotification::TYPE);

		$this->leftJoinCacheActors($qb, 'attributed_to');
		$this->leftJoinStreamAction($qb);

		return $this->getStreamsFromRequest($qb);
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
	 * @return Stream[]
	 * @throws DateTimeException
	 */
	public function getTimelineAccount(string $actorId, int $since = 0, int $limit = 5): array {
		$qb = $this->getStreamSelectSql();
		$this->limitPaginate($qb, $since, $limit);

		$this->limitToAttributedTo($qb, $actorId);
		$this->limitToRecipient($qb, ACore::CONTEXT_PUBLIC);

		$this->leftJoinCacheActors($qb, 'attributed_to');
		$this->leftJoinStreamAction($qb);

		return $this->getStreamsFromRequest($qb);
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
	 * @return Stream[]
	 * @throws DateTimeException
	 */
	public function getTimelineDirect(Person $actor, int $since = 0, int $limit = 5): array {
		$qb = $this->getStreamSelectSql();
		$this->limitPaginate($qb, $since, $limit);

		$this->limitToRecipient($qb, $actor->getId(), true);
		$this->filterRecipient($qb, ACore::CONTEXT_PUBLIC);
		$this->filterRecipient($qb, $actor->getFollowers());
		$this->filterType($qb, SocialAppNotification::TYPE);
//		$this->filterHiddenOnTimeline($qb);

		$this->leftJoinCacheActors($qb, 'attributed_to');

		return $this->getStreamsFromRequest($qb);
	}


	/**
	 * Should returns:
	 *  * All local public/federated posts
	 *
	 * @param int $since
	 * @param int $limit
	 * @param bool $localOnly
	 *
	 * @return Stream[]
	 * @throws DateTimeException
	 */
	public function getTimelineGlobal(int $since = 0, int $limit = 5, bool $localOnly = true
	): array {
		$qb = $this->getStreamSelectSql();
		$this->limitPaginate($qb, $since, $limit);

		$this->limitToLocal($qb, $localOnly);
		$qb->limitToType(Note::TYPE);

		$this->leftJoinCacheActors($qb, 'attributed_to');
		$this->leftJoinStreamAction($qb);

		// TODO: to: = real public, cc: = unlisted !?
		// DO NOT USE stream_dest.type = 'recipient' on this one !
		$this->limitToRecipient($qb, ACore::CONTEXT_PUBLIC, true, ['to']);

		return $this->getStreamsFromRequest($qb);
	}


	/**
	 * Should returns:
	 *  * All liked posts
	 *
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Stream[]
	 * @throws DateTimeException
	 */
	public function getTimelineLiked(int $since = 0, int $limit = 5): array {
		if ($this->viewer === null) {
			return [];
		}

		$actorId = $this->viewer->getId();

		$qb = $this->getStreamSelectSql();
		$qb->limitToType(Note::TYPE);
		$this->limitPaginate($qb, $since, $limit);

		$expr = $qb->expr();
		$this->selectCacheActors($qb, 'ca');
		$qb->andWhere($expr->eq('s.attributed_to_prim', 'ca.id_prim'));

		$this->selectStreamActions($qb, 'sa');
		$qb->andWhere($expr->eq('sa.stream_id_prim', 's.id_prim'));
		$qb->andWhere($expr->eq('sa.actor_id_prim', $qb->createNamedParameter($this->prim($actorId))));
		$qb->andWhere($expr->eq('sa.liked', $qb->createNamedParameter(1)));

		return $this->getStreamsFromRequest($qb);
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
	 * @return Stream[]
	 * @throws DateTimeException
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
//		$this->filterHiddenOnTimeline($qb);

		$this->leftJoinCacheActors($qb, 'attributed_to');
		$this->leftJoinStreamAction($qb);

		return $this->getStreamsFromRequest($qb);
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
		$qb->limitToType(Note::TYPE);
		$this->leftJoinStreamAction($qb);

		return $this->getStreamsFromRequest($qb);
	}


	/**
	 * @param string $id
	 * @param string $type
	 */
	public function deleteById(string $id, string $type = '') {
		$qb = $this->getStreamDeleteSql();
		$qb->limitToIdPrim($qb->prim($id));

		if ($type !== '') {
			$qb->limitToType($type);
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
		if ($stream->hasCache()) {
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
		   ->setValue('subtype', $qb->createNamedParameter($stream->getSubType()))
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
		   ->setValue('attributed_to_prim', $qb->createNamedParameter($qb->prim($attributedTo)))
		   ->setValue('in_reply_to', $qb->createNamedParameter($stream->getInReplyTo()))
		   ->setValue('source', $qb->createNamedParameter($stream->getSource()))
		   ->setValue('activity_id', $qb->createNamedParameter($stream->getActivityId()))
		   ->setValue('object_id', $qb->createNamedParameter($stream->getObjectId()))
		   ->setValue('object_id_prim', $qb->createNamedParameter($qb->prim($stream->getObjectId())))
		   ->setValue('details', $qb->createNamedParameter(json_encode($stream->getDetailsAll())))
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

		$qb->generatePrimaryKey($stream->getId(), 'id_prim');

		return $qb;
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param Person $actor
	 *
	 * @deprecated
	 */
	private function leftJoinFollowStatus(IQueryBuilder $qb, Person $actor) {
		if ($qb->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $qb->expr();
		$pf = $this->defaultSelectAlias . '.';

		$on = $expr->andX();
		$on->add($this->exprLimitToDBFieldInt($qb, 'accepted', 1, 'fs'));
		$on->add(
			$this->exprLimitToDBField($qb, 'actor_id_prim', $this->prim($actor->getId()), true, true, 'fs')
		);
		$on->add($expr->eq($pf . 'attributed_to_prim', 'fs.object_id_prim'));

		$qb->leftJoin($this->defaultSelectAlias, CoreRequestBuilder::TABLE_FOLLOWS, 'fs', $on);
	}


	/**
	 * @param IQueryBuilder $qb
	 *
	 * @deprecated
	 */
	private function filterDuplicate(IQueryBuilder $qb) {
		$actor = $this->viewer;
		if ($actor === null) {
			return;
		}

		// NEEDED ? use the follow 'f' ?
		$this->leftJoinFollowStatus($qb, $actor);

		$expr = $qb->expr();
		$filter = $expr->orX();
		$filter->add($this->exprLimitToDBFieldInt($qb, 'hidden_on_timeline', 0, 's'));

		$follower = $expr->andX();
		$follower->add(
			$this->exprLimitToDBField($qb, 'attributed_to_prim', $this->prim($actor->getId()), false)
		);
		$follower->add($expr->isNull('fs.id_prim'));
		$filter->add($follower);

		$qb->andWhere($filter);
	}

}

