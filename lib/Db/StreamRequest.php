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


use daita\MySmallPhpTools\Exceptions\DateTimeException;
use daita\MySmallPhpTools\Model\Cache;
use DateTime;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Exception;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\TimelineOptions;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\ILogger;
use OCP\IURLGenerator;


/**
 * Class StreamRequest
 *
 * @package OCA\Social\Db
 */
class StreamRequest extends StreamRequestBuilder {


	/** @var StreamDestRequest */
	private $streamDestRequest;

	/** @var StreamTagsRequest */
	private $streamTagsRequest;


	/**
	 * StreamRequest constructor.
	 *
	 * @param IDBConnection $connection
	 * @param ILogger $logger
	 * @param IURLGenerator $urlGenerator
	 * @param StreamDestRequest $streamDestRequest
	 * @param StreamTagsRequest $streamTagsRequest
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IDBConnection $connection, ILogger $logger, IURLGenerator $urlGenerator,
		StreamDestRequest $streamDestRequest, StreamTagsRequest $streamTagsRequest,
		ConfigService $configService, MiscService $miscService
	) {
		parent::__construct($connection, $logger, $urlGenerator, $configService, $miscService);

		$this->streamDestRequest = $streamDestRequest;
		$this->streamTagsRequest = $streamTagsRequest;
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
			$this->streamTagsRequest->generateStreamTags($stream);
		} catch (UniqueConstraintViolationException $e) {
		}
	}


	/**
	 * @param Stream $stream \
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
		$qb->execute();

		$this->streamDestRequest->generateStreamDest($stream);
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
		$qb->set('attachments', $qb->createNamedParameter(json_encode($new, JSON_UNESCAPED_SLASHES)));
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
		$qb->limitToIdPrim($qb->prim($id));
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		if ($asViewer) {
			$qb->limitToViewer('sd', 'f', true, true);
			$qb->leftJoinStreamAction('sa');
		}

		try {
			return $this->getStreamFromRequest($qb);
		} catch (ItemUnknownException $e) {
			throw new StreamNotFoundException('Malformed Stream');
		} catch (StreamNotFoundException $e) {
			throw new StreamNotFoundException('Stream not found');
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
		$qb->limitToInReplyTo($id);
		$qb->limitPaginate($since, $limit);

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		if ($asViewer) {
			$qb->limitToViewer('sd', 'f', true);
			$qb->leftJoinStreamAction();
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
		$qb->limitToActivityId($id);

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
		$qb->limitToObjectId($objectId);
		$qb->limitToType($type);
		$qb->limitToSubType($subType);

		return $this->getStreamFromRequest($qb);
	}


	/**
	 * @param string $actorId
	 *
	 * @return int
	 */
	public function countNotesFromActorId(string $actorId): int {
		$qb = $this->countNotesSelectSql();
		$qb->limitToAttributedTo($actorId, true);
		$qb->limitToType(Note::TYPE);

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinSteamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}


	/**
	 * @param string $actorId
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	public function lastNoteFromActorId(string $actorId): Stream {
		$qb = $this->getStreamSelectSql();
		$qb->limitToAttributedTo($actorId, true);
		$qb->limitToType(Note::TYPE);

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinSteamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$qb->orderBy('id', 'desc');
		$qb->setMaxResults(1);

		return $this->getStreamFromRequest($qb);
	}


	/**
	 * Should returns:
	 *  * Own posts,
	 *  * Followed accounts
	 *
	 * @param TimelineOptions $options
	 *
	 * @return Stream[]
	 */
	public function getTimelineHome(TimelineOptions $options): array {
		$qb = $this->getStreamSelectSql($options->getFormat());
		$qb->setChunk(1);

		$qb->filterType(SocialAppNotification::TYPE);
		$qb->paginate($options);

		$qb->limitToViewer('sd', 'f', false);
		$this->timelineHomeLinkCacheActor($qb, 'ca', 'f');

		$qb->leftJoinStreamAction('sa');
		$qb->filterDuplicate();

		$result = $this->getStreamsFromRequest($qb);
		if ($options->isInverted()) {
			$result = array_reverse($result);
		}

		return $result;
	}


	/**
	 * Should returns:
	 *  * Own posts,
	 *  * Followed accounts
	 *
	 * @param int $since
	 * @param int $limit
	 * @param int $format
	 *
	 * @return Stream[]
	 * @throws DateTimeException
	 * @deprecated - use GetTimeline()
	 */
	public function getTimelineHome_dep(
		int $since = 0, int $limit = 5, int $format = Stream::FORMAT_ACTIVITYPUB
	): array {
		$qb = $this->getStreamSelectSql($format);
		$qb->setChunk(1);

		$qb->filterType(SocialAppNotification::TYPE);
		$qb->limitPaginate($since, $limit);

		$qb->limitToViewer('sd', 'f', false);
		$this->timelineHomeLinkCacheActor($qb, 'ca', 'f');

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
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Stream[]
	 * @throws DateTimeException
	 */
	public function getTimelineNotifications(int $since = 0, int $limit = 5): array {
		$qb = $this->getStreamSelectSql();

		$actor = $qb->getViewer();

		$qb->limitPaginate($since, $limit);

		$qb->selectDestFollowing('sd', '');
		$qb->limitToDest($actor->getId(), 'notif', '', 'sd');
		$qb->limitToType(SocialAppNotification::TYPE);

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction();

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
		$qb->limitPaginate($since, $limit);

		$qb->limitToAttributedTo($actorId);

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinSteamDest('recipient', 'id_prim', 'sd', 's');
		$accountIsViewer = ($qb->hasViewer()) ? ($qb->getViewer()->getId() === $actorId) : false;
		$qb->limitToDest($accountIsViewer ? '' : ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction();

		return $this->getStreamsFromRequest($qb);
	}


	/**
	 * Should returns:
	 *  * Private message.
	 *  - group messages. (not yet)
	 *
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Stream[]
	 * @throws DateTimeException
	 */
	public function getTimelineDirect(int $since = 0, int $limit = 5): array {
		$qb = $this->getStreamSelectSql();

		$qb->filterType(SocialAppNotification::TYPE);
		$qb->limitPaginate($since, $limit);

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		$viewer = $qb->getViewer();
		$qb->selectDestFollowing('sd', '');
		$qb->limitToDest($viewer->getId(), 'dm', '', 'sd');

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
		$qb->limitPaginate($since, $limit);

		$qb->limitToLocal($localOnly);
		$qb->limitToType(Note::TYPE);

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction();

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinSteamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', 'to', 'sd');

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
		$qb = $this->getStreamSelectSql();
		if (!$qb->hasViewer()) {
			return [];
		}

		$actor = $qb->getViewer();

		$qb->limitToType(Note::TYPE);
		$qb->limitPaginate($since, $limit);

		$expr = $qb->expr();
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		$qb->selectStreamActions('sa');
		$qb->andWhere($expr->eq('sa.stream_id_prim', 's.id_prim'));
		$qb->andWhere($expr->eq('sa.actor_id_prim', $qb->createNamedParameter($qb->prim($actor->getId()))));
		$qb->andWhere($expr->eq('sa.liked', $qb->createNamedParameter(1)));

		return $this->getStreamsFromRequest($qb);
	}


	/**
	 * Should returns:
	 *  - All public post related to a tag (not yet)
	 *  - direct message related to a tag (not yet)
	 *  - message to followers related to a tag (not yet)
	 *
	 * @param string $hashtag
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Stream[]
	 * @throws DateTimeException
	 */
	public function getTimelineTag(string $hashtag, int $since = 0, int $limit = 5): array {
		$qb = $this->getStreamSelectSql();

		$expr = $qb->expr();
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->linkToStreamTags('st', 's.id_prim');
		$qb->limitPaginate($since, $limit);

		$qb->andWhere($qb->exprLimitToDBField('type', Note::TYPE));
		$qb->andWhere($qb->exprLimitToDBField('hashtag', $hashtag, true, false, 'st'));

		$qb->limitToViewer('sd', 'f', true);
		$qb->andWhere($expr->eq('s.attributed_to_prim', 'ca.id_prim'));

		$qb->leftJoinStreamAction('sa');

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
		$qb->limitToSince($since, 'published_time');
		$qb->limitToType(Note::TYPE);
		$qb->leftJoinStreamAction();

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
		$qb->limitToAttributedTo($actorId, true);

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
		   ->setValue('in_reply_to_prim', $qb->createNamedParameter($qb->prim($stream->getInReplyTo())))
		   ->setValue('source', $qb->createNamedParameter($stream->getSource()))
		   ->setValue('activity_id', $qb->createNamedParameter($stream->getActivityId()))
		   ->setValue('object_id', $qb->createNamedParameter($stream->getObjectId()))
		   ->setValue('object_id_prim', $qb->createNamedParameter($qb->prim($stream->getObjectId())))
		   ->setValue('details', $qb->createNamedParameter(json_encode($stream->getDetailsAll())))
		   ->setValue('cache', $qb->createNamedParameter($cache))
		   ->setValue(
			   'filter_duplicate',
			   $qb->createNamedParameter(($stream->isFilterDuplicate()) ? '1' : '0')
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

}

