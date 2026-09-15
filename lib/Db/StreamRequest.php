<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Moderation;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\MiscService;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCA\Social\Tools\Model\Cache;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Class StreamRequest
 *
 * @package OCA\Social\Db
 */
class StreamRequest extends StreamRequestBuilder {
	/**
	 * The accounts every post of which a moderator has marked sensitive, read
	 * once per request. Null until something is saved.
	 *
	 * @var string[]|null
	 */
	private ?array $forcedSensitive = null;

	/**
	 * The width of the random half of a nid.
	 *
	 * A nid is `published_time * NID_LIMIT + random`, which keeps it sortable by
	 * publication time -- cursor pagination relies on that -- while staying
	 * opaque within a second. It is also the primary key of social_stream, so a
	 * collision is a rejected insert, and save() used to swallow exactly that
	 * error: the post was silently lost.
	 *
	 * At the previous width of 1e6 the birthday bound put an even chance of a
	 * collision at about 1,200 posts sharing a second. 1e9 moves that to roughly
	 * 37,000, and published_time * 1e9 still fits a BIGINT for any date this app
	 * will see. Widening does not disturb the ordering of ids issued under the
	 * old width: every new nid is larger than every old one, and both halves
	 * stay monotonic in time.
	 */
	private const NID_LIMIT = 1000000000;

	/** How many fresh nids to try before giving up on an insert. */
	private const NID_ATTEMPTS = 4;

	/** How many posts one pass of deleteByAuthor() removes. */
	public const DELETE_BATCH = 500;

	/**
	 * How far back the closed-poll sweep reads.
	 *
	 * Mastodon caps a poll at six months, so a poll published longer ago than
	 * that has closed already and is not news to anybody.
	 */
	public const POLL_LOOKBACK = 190 * 86400;

	public function __construct(
		IDBConnection $connection,
		LoggerInterface $logger,
		IURLGenerator $urlGenerator,
		private StreamDestRequest $streamDestRequest,
		private StreamTagsRequest $streamTagsRequest,
		ConfigService $configService,
		MiscService $miscService,
		private ModerationRequest $moderationRequest,
		private FediverseService $fediverseService,
		private CacheDocumentService $cacheDocumentService,
		private FollowedTagsRequest $followedTagsRequest,
		private ConversationsRequest $conversationsRequest,
	) {
		parent::__construct($connection, $logger, $urlGenerator, $configService, $miscService);
	}

	public function save(Stream $stream): void {
		$this->applyForcedSensitive($stream);

		for ($attempt = 1; ; $attempt++) {
			$qb = $this->saveStream($stream);
			if ($stream->getType() === Note::TYPE) {
				/** @var Note $stream */

				$attachments = [];
				foreach ($stream->getAttachments() as $item) {
					$attachments[] = $item->asLocal(); // get attachment ready for local
				}

				$qb->setValue('hashtags', $qb->createNamedParameter(json_encode($stream->getHashtags())))
					->setValue(
						'attachments', $qb->createNamedParameter(json_encode($attachments, JSON_UNESCAPED_SLASHES)
						)
					);
			}

			try {
				// One transaction, because the three writes are one fact. The
				// recipient rows are what put a post in a timeline: a post
				// stored without them exists, is in nobody's timeline, and
				// nothing ever notices — `StreamDestRequest::create()` logs a
				// failure and carries on, so the partial state was silent as
				// well as permanent.
				$this->dbConnection->beginTransaction();

				try {
					$qb->executeStatement();

					$this->streamDestRequest->generateStreamDest($stream);
					$this->streamTagsRequest->generateStreamTags($stream);

					$this->dbConnection->commit();
				} catch (\Throwable $t) {
					$this->dbConnection->rollBack();

					throw $t;
				}

				return;
			} catch (DBException $e) {
				if ($e->getReason() !== DBException::REASON_CONSTRAINT_VIOLATION) {
					$this->logger->error("Couldn't save stream: " . $e->getMessage(), [
						'exception' => $e,
					]);

					return;
				}

				// Two different constraints reach here and they want opposite
				// things. A second save of the same status trips the unique
				// index on id_prim, and dropping it is right -- that is what
				// makes an inbox delivery idempotent. A nid collision trips the
				// primary key, and dropping that loses a post that was never
				// stored. The databases do not agree on how to tell the two
				// apart from the exception, so ask instead whether the status is
				// already there; if it is not, the clash was on the nid.
				if ($attempt >= self::NID_ATTEMPTS || $this->has($stream->getId())) {
					if ($attempt >= self::NID_ATTEMPTS) {
						$this->logger->error(
							'Could not find a free nid for stream ' . $stream->getId()
							. ' in ' . self::NID_ATTEMPTS . ' attempts; the post was not stored.',
							['exception' => $e]
						);
					}

					return;
				}

				// force saveStream() to draw a fresh one
				$stream->setNid(0);
			}
		}
	}

	/** Whether a status with this ActivityPub id is already stored. */
	private function has(string $id): bool {
		if ($id === '') {
			return false;
		}

		try {
			$qb = $this->getStreamSelectSql();
			$qb->limitToIdPrim($qb->prim($id));
			$this->getStreamFromRequest($qb);

			return true;
		} catch (StreamNotFoundException) {
			return false;
		}
	}

	public function update(Stream $stream, bool $generateDest = false): void {
		$qb = $this->getStreamUpdateSql();

		$qb->set('to', $qb->createNamedParameter($stream->getTo()));
		$qb->set(
			'cc', $qb->createNamedParameter(json_encode($stream->getCcArray(), JSON_UNESCAPED_SLASHES))
		);
		$qb->set(
			'to_array', $qb->createNamedParameter(json_encode($stream->getToArray(), JSON_UNESCAPED_SLASHES))
		);
		$qb->set('content', $qb->createNamedParameter($stream->getContent()));
		$qb->set('summary', $qb->createNamedParameter($stream->getSummary()));
		$qb->set('sensitive', $qb->createNamedParameter($stream->isSensitive() ? 1 : 0));
		$qb->set('source', $qb->createNamedParameter($stream->getSource()));
		// the five fields an Update rewrites, in their own columns since
		// Version1000Date20260912000007. They are still inside the wire object
		// this same statement stores, and still read from there for a row
		// written before that step — but an edit that changed the language or
		// took an approval back has to change the column too, or the column and
		// the object it was copied from disagree from the next read on.
		$this->setPostFields($qb, $stream, false);
		if ($stream->getType() === Note::TYPE && $stream instanceof Note) {
			$qb->set('hashtags', $qb->createNamedParameter(json_encode($stream->getHashtags(), JSON_UNESCAPED_SLASHES)));
			$qb->set(
				'attachments', $qb->createNamedParameter(
					json_encode($stream->getAttachments(), JSON_UNESCAPED_SLASHES)
				)
			);
		}
		$qb->set('published', $qb->createNamedParameter($stream->getPublished()));
		try {
			$dTime = new DateTime();
			$dTime->setTimestamp($stream->getPublishedTime());
			$qb->set('published_time', $qb->createNamedParameter($dTime, IQueryBuilder::PARAM_DATE));
		} catch (Exception $e) {
		}
		$qb->limitToIdPrim($qb->prim($stream->getId()));
		$qb->executeStatement();

		if ($generateDest) {
			$this->streamDestRequest->generateStreamDest($stream);
		}
	}

	public function updateDetails(Stream $stream): void {
		$qb = $this->getStreamUpdateSql();
		$qb->set('details', $qb->createNamedParameter(json_encode($stream->getDetailsAll())));
		$qb->limitToIdPrim($qb->prim($stream->getId()));
		$qb->executeStatement();
	}

	/**
	 * Counts the replies to a post again and stores the total on it.
	 *
	 * A recount rather than a bump, because the two things that change it —
	 * a reply arriving and a reply being deleted — do not both know which way.
	 * `remote_replies` is what the post's own instance reported and is carried
	 * across untouched: nothing here can see the replies that live over there.
	 *
	 * @param string $inReplyTo the id of the post that was replied to
	 */
	public function recountReplies(string $inReplyTo): void {
		if ($inReplyTo === '') {
			return;
		}

		try {
			$parent = $this->getStreamById($inReplyTo);
		} catch (StreamNotFoundException $e) {
			return;
		}

		$parent->setDetailInt(
			'replies', $parent->getDetailInt('remote_replies') + $this->countRepliesTo($inReplyTo)
		);
		$this->updateDetails($parent);
	}

	public function updateCache(Stream $stream, Cache $cache): void {
		$qb = $this->getStreamUpdateSql();
		$qb->set('cache', $qb->createNamedParameter(json_encode($cache, JSON_UNESCAPED_SLASHES)));

		$qb->limitToIdPrim($qb->prim($stream->getId()));

		$qb->executeStatement();
	}

	/**
	 * The posts that carry a stored attachment copy, a page at a time.
	 *
	 * A post keeps its own copy of its attachments (`save()` writes
	 * `asLocal()` into the `attachments` column), so anything that changes a
	 * *document* after the fact -- a poster frame made for a video that was
	 * stored before posters existed -- never reaches the post that shows it.
	 * `occ social:media:posters` walks these and rewrites the copies.
	 *
	 * Only the id and the blob are read: rebuilding a whole `Stream` with its
	 * joins, for every post with a picture on the instance, would be a great
	 * deal of work to reach one column.
	 *
	 * @return array<array{nid: int, id: string, attachments: string}>
	 */
	public function getStoredAttachmentCopies(int $limit, int $after = 0): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('nid', 'id', 'attachments')
			->from(self::TABLE_STREAM)
			->andWhere($expr->neq('attachments', $qb->createNamedParameter('')))
			->andWhere($expr->neq('attachments', $qb->createNamedParameter('[]')))
			->andWhere($expr->gt('nid', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
			->orderBy('nid', 'asc')
			->setMaxResults($limit);

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = [
				'nid' => (int)$data['nid'],
				'id' => (string)$data['id'],
				'attachments' => (string)$data['attachments'],
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	/** Replaces one post's stored attachment copies with the JSON given. */
	public function setStoredAttachmentCopies(string $id, string $attachments): void {
		$qb = $this->getStreamUpdateSql();
		$qb->set('attachments', $qb->createNamedParameter($attachments));
		$qb->limitToIdPrim($qb->prim($id));

		$qb->executeStatement();
	}

	public function updateAttachments(Document $document): void {
		$qb = $this->getStreamSelectSql();
		$qb->limitToIdPrim($qb->prim($document->getParentId()));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			return;
		}

		$new = $this->updateAttachmentInList($document, $this->getArray('attachments', $data, []));
		$qb = $this->getStreamUpdateSql();
		$qb->set('attachments', $qb->createNamedParameter(json_encode($new, JSON_UNESCAPED_SLASHES)));
		$qb->limitToIdPrim($qb->prim($document->getParentId()));

		$qb->executeStatement();
	}

	/**
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

	public function updateAttributedTo(string $itemId, string $to): void {
		$qb = $this->getStreamUpdateSql();
		$qb->set('attributed_to', $qb->createNamedParameter($to));
		$qb->set('attributed_to_prim', $qb->createNamedParameter($qb->prim($to)));

		$qb->limitToIdPrim($qb->prim($itemId));

		$qb->executeStatement();
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
	 * @param int $format
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	public function getStreamById(
		string $id,
		bool $asViewer = false,
		int $format = ACore::FORMAT_ACTIVITYPUB,
	): Stream {
		if ($id === '') {
			throw new StreamNotFoundException();
		};

		$qb = $this->getStreamSelectSql($format);
		$qb->limitToIdPrim($qb->prim($id));
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		if ($asViewer) {
			$qb->limitToViewer('sd', 'f', true, true, SocialCoreQueryBuilder::HIDDEN_DIRECT);
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
	 * @param bool $asViewer
	 * @param int $format
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	/**
	 * Full-text search over the statuses the viewer is allowed to see: their
	 * own posts, public/unlisted content, and what is addressed to them. A
	 * plain (case-insensitive) substring match — fine at the instance sizes
	 * this app targets; no external search engine required.
	 *
	 * @return Stream[]
	 */
	public function searchContent(string $term, int $limit = 20): array {
		if (strlen($term) < 3) {
			return [];
		}

		$qb = $this->getStreamSelectSql(ACore::FORMAT_LOCAL);
		$qb->limitToStatusTypes();
		$expr = $qb->expr();
		$qb->andWhere($expr->iLike(
			's.content',
			$qb->createNamedParameter('%' . $this->dbConnection->escapeLikeParameter($term) . '%')
		));

		$qb->limitToViewer('sd', 'f', true, true, SocialCoreQueryBuilder::HIDDEN_DIRECT);
		$qb->leftJoinStreamAction();
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->orderBy('s.published_time', 'desc');
		$qb->setMaxResults($limit);

		return $this->getStreamsFromRequest($qb);
	}

	public function getStreamByNid(int $nid): Stream {
		$qb = $this->getStreamSelectSql(ACore::FORMAT_LOCAL);
		$qb->limitToNid($nid);
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		$qb->limitToViewer('sd', 'f', true, true, SocialCoreQueryBuilder::HIDDEN_DIRECT);
		$qb->leftJoinStreamAction('sa');

		return $this->getStreamFromRequest($qb);
	}

	/**
	 * @param string $idPrim
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	public function getStream(string $idPrim): Stream {
		$qb = $this->getStreamSelectSql();
		$qb->limitToIdPrim($idPrim);

		return $this->getStreamFromRequest($qb);
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
	public function getRepliesByParentId(string $id, int $since = 0, int $limit = 5, bool $asViewer = false,
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
	public function getStreamByObjectId(string $objectId, string $type, string $subType = '',
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
	 * The public replies to a post, oldest first, for the `replies` collection
	 * a peer walks to discover a thread.
	 *
	 * Public only. The collection is served to anybody who asks for it, and a
	 * followers-only or direct reply is not theirs to read — not even as an id,
	 * which is enough to fetch the reply itself from the instance that holds
	 * it. This is the same audience test `getPublicByAuthor()` applies to an
	 * outbox.
	 *
	 * Oldest first, because that is the order a thread is read in and the order
	 * `OrderedCollectionPage` offsets are stable under: newest-first paging
	 * renumbers every page as soon as somebody replies again.
	 *
	 * @return Stream[]
	 */
	public function getPublicRepliesTo(string $id, int $limit, int $offset = 0): array {
		if ($id === '' || $limit < 1) {
			return [];
		}

		$qb = $this->getStreamSelectSql();
		$qb->limitToInReplyTo($id, true);
		$qb->limitToStatusTypes();

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		$qb->orderBy('s.published_time', 'asc');
		$qb->setMaxResults($limit);
		$qb->setFirstResult($offset);

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * How many replies {@see self::getPublicRepliesTo()} would return: the
	 * `totalItems` of the collection, counted over the same audience so the
	 * number and the pages cannot disagree.
	 */
	public function countPublicRepliesTo(string $id): int {
		if ($id === '') {
			return 0;
		}

		$qb = $this->countNotesSelectSql();
		$qb->limitToInReplyTo($id, true);
		$qb->limitToStatusTypes();

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * @param string $id
	 *
	 * @return int
	 */
	public function countRepliesTo(string $id): int {
		$qb = $this->countNotesSelectSql();
		$qb->limitToInReplyTo($id, true);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * A moderator's decision that every post by an account is sensitive.
	 *
	 * Applied here, at the one place a post is written, rather than at each of
	 * the paths that reach it: a local post, a post that arrived in the inbox
	 * and a post restored by the importer are the same row, and a rule that
	 * held for one of them and not the others would be a rule nobody could
	 * explain.
	 *
	 * The set is read once per request and is empty on nearly every instance,
	 * so the common case is one query that returns nothing and a comparison
	 * against an empty array.
	 */
	private function applyForcedSensitive(Stream $stream): void {
		if ($stream->isSensitive() || $stream->getAttributedTo() === '') {
			return;
		}

		$this->forcedSensitive ??= $this->moderationRequest->forcedSensitive();
		if (in_array($stream->getAttributedTo(), $this->forcedSensitive, true)) {
			$stream->setSensitive(true);
		}
	}

	/**
	 * Puts one of the author's own posts away, or brings it back.
	 *
	 * The author is in the statement rather than checked beforehand: a request
	 * naming somebody else's post changes no row, which is the same guarantee
	 * every other per-account write here makes and one that cannot be
	 * forgotten by a caller.
	 *
	 * Local posts only. Archiving is about what this account shows on its own
	 * profile; a post somebody else wrote is theirs, and the answer to not
	 * wanting to see it is a mute or a block.
	 *
	 * @return bool whether a row changed
	 */
	public function setArchived(int $nid, string $actorId, bool $archived): bool {
		$qb = $this->getStreamUpdateSql();
		$qb->set('archived', $qb->createNamedParameter($archived, IQueryBuilder::PARAM_BOOL));
		$qb->where(
			$qb->expr()->eq('nid', $qb->createNamedParameter($nid, IQueryBuilder::PARAM_INT)),
			$qb->expr()->eq('attributed_to_prim', $qb->createNamedParameter($qb->prim($actorId))),
			$qb->expr()->eq('local', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
		);

		return $qb->executeStatement() > 0;
	}

	/**
	 * The posts this account has put away, newest first.
	 *
	 * The one read that asks for archived posts, and the only one: everything
	 * else is fail-closed (`hideArchived()`).
	 *
	 * @return Stream[]
	 */
	public function getArchivedByActor(string $actorId, int $limit = 50, int $maxId = 0): array {
		$qb = $this->getStreamSelectSql(Stream::FORMAT_LOCAL, true);
		$qb->andWhere($qb->expr()->eq('s.attributed_to_prim', $qb->createNamedParameter($qb->prim($actorId))));
		$qb->andWhere($qb->expr()->eq('s.archived', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		if ($maxId > 0) {
			$qb->andWhere($qb->expr()->lt('s.nid', $qb->createNamedParameter($maxId, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('s.nid', 'desc');
		$qb->setMaxResults($limit);

		return $this->getStreamsFromRequest($qb);
	}

	/** How many posts this account has put away. */
	public function countArchivedByActor(string $actorId): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_STREAM, 's')
			->where($qb->expr()->eq('s.attributed_to_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->eq('s.archived', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($data['total'] ?? 0);
	}

	/**
	 * What this instance's own people have been writing, for the two numbers
	 * an administrator asks for first: how busy is it, and how many of the
	 * accounts are actually used.
	 *
	 * Local posts only. A count that included the fediverse's would say how
	 * much this server has *received*, which is a number about everybody
	 * else's activity and about this instance's retention settings.
	 *
	 * @param int $since unix time to count from
	 * @return array{posts: int, authors: int}
	 */
	public function localActivitySince(int $since): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$date = new DateTime();
		$date->setTimestamp($since);

		$qb->selectAlias($qb->func()->count('s.id'), 'posts')
			->selectAlias($qb->createFunction('COUNT(DISTINCT s.attributed_to_prim)'), 'authors')
			->from(self::TABLE_STREAM, 's')
			->where($expr->eq('s.local', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($expr->in(
				's.type',
				$qb->createNamedParameter([Note::TYPE, Question::TYPE], IQueryBuilder::PARAM_STR_ARRAY)
			))
			->andWhere($expr->gte(
				's.published_time', $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE)
			));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return [
			'posts' => (int)($data['posts'] ?? 0),
			'authors' => (int)($data['authors'] ?? 0),
		];
	}

	/**
	 * How many posts this account has published here to anybody but one person.
	 *
	 * The twin of `countNotesFromActorId()`, which counts only the public ones
	 * because that is what a profile reports. This one is asked a different
	 * question — has this account posted here before at all — so a first post
	 * that went to followers is still not a first post.
	 *
	 * Direct messages are left out, and that is the point rather than a
	 * detail. `PostReviewService` never holds a direct message, so counting
	 * them here would mean an account could send one message to itself and be
	 * past first-post review a second later — the rule would hold nobody who
	 * had read the rule.
	 */
	public function countPostsBy(string $actorId): int {
		$qb = $this->countNotesSelectSql();
		$qb->limitToAttributedTo($actorId, true);
		$qb->limitToStatusTypes();
		$qb->andWhere(
			$qb->expr()->neq(
				's.visibility', $qb->createNamedParameter(Stream::TYPE_DIRECT)
			)
		);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * @param string $actorId
	 *
	 * @return int
	 */
	public function countNotesFromActorId(string $actorId): int {
		$qb = $this->countNotesSelectSql();
		$qb->limitToAttributedTo($actorId, true);
		$qb->limitToStatusTypes();

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$cursor = $qb->executeQuery();
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
		$qb->limitToStatusTypes();

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		// qualified, and by the time rather than the id: `id` is a column on the
		// joined dest table too, so the unqualified name was ambiguous and the
		// database refused the query outright — and the id it meant to sort on
		// is the post's URL, which says nothing about when it was written
		$qb->orderBy('s.published_time', 'desc');
		$qb->setMaxResults(1);

		return $this->getStreamFromRequest($qb);
	}

	/**
	 * The public posts of one author, oldest last, in fixed-size windows.
	 *
	 * The outbox collection is paged by page number rather than by cursor —
	 * that is what the collection itself advertises, and what a consumer
	 * walking `first`/`next` follows — so this takes an offset instead of the
	 * `since` the client timelines use.
	 *
	 * @return Stream[]
	 */
	public function getPublicByAuthor(string $actorId, int $limit, int $offset = 0): array {
		if ($actorId === '' || $limit < 1) {
			return [];
		}

		$qb = $this->getStreamSelectSql();
		$qb->limitToStatusTypes();
		$qb->limitToAttributedTo($actorId, true);

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		$qb->orderBy('s.published_time', 'desc');
		$qb->setMaxResults($limit);
		$qb->setFirstResult($offset);

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	public function getTimeline(ProbeOptions $options): array {
		switch (strtolower($options->getProbe())) {
			case ProbeOptions::ACCOUNT:
				$result = $this->getTimelineAccount($options);
				break;
			case ProbeOptions::HOME:
				$result = $this->getTimelineHome($options);
				break;
			case ProbeOptions::DIRECT:
				$result = $this->getTimelineDirect($options);
				break;
			case ProbeOptions::FAVOURITES:
				$result = $this->getTimelineFavourites($options);
				break;
			case ProbeOptions::BOOKMARKS:
				$result = $this->getTimelineBookmarks($options);
				break;
			case ProbeOptions::HASHTAG:
				$result = $this->getTimelineHashtag($options);
				break;
			case ProbeOptions::NOTIFICATIONS:
				$options->setFormat(ACore::FORMAT_NOTIFICATION);
				$result = $this->getTimelineNotifications($options);
				break;
			case ProbeOptions::PUBLIC:
				$result = $this->getTimelinePublic($options);
				break;
			default:
				return [];
		}

		if ($options->isInverted()) {
			// in case we inverted the order during the request, we revert the results
			$result = array_reverse($result);
		}

		return $result;
	}

	/**
	 * Should return:
	 *  * Own posts,
	 *  * Followed accounts,
	 *  * Public posts carrying a hashtag the viewer follows
	 *
	 * Two pages of ids, not one query. A post belongs here because of its
	 * author *or* because of its tags, and written as one query that is an OR
	 * across two different joins: no index can serve both sides of it, so the
	 * database falls back to reading the stream table. Each half on its own is
	 * a query over one indexed column, which is the shape the rest of this
	 * method depends on.
	 *
	 * Merging them here is exact rather than approximate. Both halves are
	 * ordered and cut to the same limit, so anything that belongs in the top
	 * `limit` of the union is in the top `limit` of the half it came from —
	 * there can be at most `limit - 1` ids above it in the union, hence at
	 * most that many in its own half. Sorting the two and cutting is therefore
	 * the same page one query would have produced.
	 *
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	private function getTimelineHome(ProbeOptions $options): array {
		// which posts, decided over one column, then what they say
		$nids = $this->homeTimelineNids($options);

		if ($this->followsAnyTag()) {
			$nids = $this->mergeNidPages($nids, $this->followedTagNids($options), $options);
		}

		if ($nids === []) {
			return [];
		}

		return $this->streamsByNids($nids, $options);
	}

	/**
	 * Applies `only_media` and `only_video` when the caller asked for them.
	 *
	 * The first has been parsed off the request since the hashtag timeline
	 * gained it and was never applied to a query, so `only_media=true` quietly
	 * returned everything. Every timeline that can carry media runs it through
	 * here, so the dedicated photo timeline and a client asking Mastodon's
	 * question of any other list get the same answer.
	 *
	 * `only_video` is this app's own and narrower; the video timeline sends
	 * it. Both may be sent at once -- the narrower one then decides, since
	 * every video is media.
	 *
	 * `media_type` is narrower still and names the kind, which is what a
	 * profile's Photos and Videos tabs ask; `media_type=video` is the same
	 * question `only_video` asks and is answered by the same predicate.
	 */
	private function filterMedia(SocialQueryBuilder $qb, ProbeOptions $options): void {
		// `media_type=video` and `only_video` are the same question, so they
		// are one predicate: a profile's Videos tab and the Videos timeline
		// cannot come to disagree about whether a PeerTube `Video` is a video.
		if ($options->isOnlyVideo() || $options->getMediaType() === 'video') {
			$qb->limitToVideo();

			return;
		}

		// any other kind narrows `only_media` to attachments of that type,
		// which is what a profile's Photos tab asks. It implies `only_media`:
		// a post with no attachments cannot be one carrying a picture, and
		// saying so here means a caller that sends only `media_type` gets what
		// they asked for rather than everything.
		if ($options->getMediaType() !== '') {
			$qb->limitToMedia();
			$qb->limitToMediaType($options->getMediaType());

			return;
		}

		if ($options->isOnlyMedia()) {
			$qb->limitToMedia();
		}
	}

	/**
	 * The page of the home timeline that the viewer's follows put there.
	 *
	 * @return int[]
	 */
	protected function homeTimelineNids(ProbeOptions $options): array {
		$page = $this->getStreamNidsSelectSql();
		$this->homeTimelineFilters($page, $options, false);

		return $this->getNidsFromRequest($page);
	}

	/**
	 * The page of the home timeline that the viewer's followed hashtags put
	 * there: public posts carrying one of them.
	 *
	 * Public only — a followed hashtag is not a relationship with the author,
	 * so it may not reach past what any stranger can read. Every other filter
	 * the follows half applies holds here too: notifications are not posts,
	 * the viewer's own boosts are not shown back to them, blocked and muted
	 * accounts stay hidden, and a silenced account is out of the public square
	 * this half reads from, exactly as it is out of the hashtag timeline.
	 *
	 * @return int[]
	 */
	protected function followedTagNids(ProbeOptions $options): array {
		$page = $this->getStreamNidsSelectSql();

		$page->filterType(SocialAppNotification::TYPE);
		$page->paginate($options);
		$this->filterMedia($page, $options);
		$page->limitToFollowedTags('ft_st', 'ft');
		$page->selectDestFollowing('ft_sd', '');
		$page->innerJoinStreamDest('recipient', 'id_prim', 'ft_sd', 's');
		$page->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'ft_sd');
		$page->filterHiddenActors();
		$page->filterDuplicate();
		$this->filterSilencedActors($page);

		return $this->getNidsFromRequest($page);
	}

	/**
	 * The polls whose end time has passed since a moment.
	 *
	 * A poll's end time lives in the stored wire object rather than in a
	 * column, so it cannot be a predicate: what this does is read the recent
	 * `Question` rows and let the model answer. That is bounded twice over —
	 * by how far back it looks, and by `$scan` — and on any instance the set
	 * is a handful of rows, because a poll is a rare kind of post and one that
	 * closed a year ago is not news.
	 *
	 * @return Question[]
	 */
	public function getPollsClosedSince(int $since, int $limit = 50, int $scan = 500): array {
		$qb = $this->getStreamSelectSql(ACore::FORMAT_LOCAL);
		$qb->limitToType(Question::TYPE);
		$qb->andWhere($qb->expr()->gte(
			's.published_time',
			$qb->createNamedParameter(
				(new DateTime())->setTimestamp(time() - self::POLL_LOOKBACK),
				IQueryBuilder::PARAM_DATE
			)
		));
		$qb->orderBy('s.nid', 'desc');
		$qb->setMaxResults(max(1, $scan));
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		$closed = [];
		foreach ($this->getStreamsFromRequest($qb) as $poll) {
			if (!($poll instanceof Question)) {
				continue;
			}

			$ends = $poll->getEndTime() === '' ? 0 : (int)strtotime($poll->getEndTime());
			if ($ends > $since && $ends <= time()) {
				$closed[] = $poll;
			}

			if (count($closed) >= $limit) {
				break;
			}
		}

		return $closed;
	}

	/**
	 * Whether the second half is worth asking for at all.
	 *
	 * One count answered out of the `(actor_id_prim, hashtag)` index without
	 * reading a row, and it is the whole cost of this feature to the accounts
	 * that follow no tag — which is all of them until they say otherwise.
	 */
	private function followsAnyTag(): bool {
		return $this->viewer !== null
			&& $this->followedTagsRequest->countByActor($this->viewer->getId()) > 0;
	}

	/**
	 * One page out of two, in the order the page is asked for, with nothing
	 * twice: a post by somebody the viewer follows that also carries a tag
	 * they follow is in both halves and is one post.
	 *
	 * @param int[] $first
	 * @param int[] $second
	 *
	 * @return int[]
	 */
	private function mergeNidPages(array $first, array $second, ProbeOptions $options): array {
		if ($second === []) {
			return $first;
		}

		$nids = array_values(array_unique(array_merge($first, $second)));
		if ($options->isInverted()) {
			sort($nids);
		} else {
			rsort($nids);
		}

		return array_slice($nids, 0, $options->getLimit());
	}

	/**
	 * The rows of a page that has already been decided, in its order.
	 *
	 * @param int[] $nids
	 *
	 * @return Stream[]
	 */
	protected function streamsByNids(array $nids, ProbeOptions $options): array {
		$qb = $this->getStreamSelectSql($options->getFormat());
		$qb->andWhere(
			$qb->expr()->in('s.nid', $qb->createNamedParameter($nids, IQueryBuilder::PARAM_INT_ARRAY))
		);
		$qb->orderBy('s.nid', $options->isInverted() ? 'asc' : 'desc');

		// the author, for the row; the follows table stays out of this query
		// because the page has already decided what belongs in it
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction('sa');
		$qb->leftJoinObjectStatus();

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * Everything that decides whether a post belongs in the viewer's home
	 * timeline. Applied to the query that picks the page; the query that reads
	 * the rows afterwards needs none of it, because the page already said which.
	 */
	/**
	 * @param SocialQueryBuilder $qb the query being built
	 * @param ProbeOptions $options what was asked for
	 * @param bool $select whether the joined author's columns are wanted; the
	 *                     page-selection query projects nids and does not want
	 *                     several kilobytes of actor per row inside its
	 *                     `SELECT DISTINCT`
	 */
	private function homeTimelineFilters(
		SocialQueryBuilder $qb, ProbeOptions $options, bool $select = true,
	): void {
		$qb->filterType(SocialAppNotification::TYPE);
		$qb->paginate($options);
		$this->filterMedia($qb, $options);
		$qb->limitToViewer('sd', 'f', false);
		// "show me this account, not what they pass on"
		$qb->filterHiddenBoosts();
		// a filter, not a join: it constrains on the follow's type
		$this->timelineHomeLinkCacheActor($qb, 'ca', 'f', $select);
		$qb->filterDuplicate();
	}

	/**
	 * Should return:
	 *  * Private message.
	 *  - group messages. (not yet)
	 *
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	private function getTimelineDirect(ProbeOptions $options): array {
		// two queries, as every other timeline: which posts, decided over one
		// indexed column, then what they say
		$nids = $this->directTimelineNids($options);
		if ($nids === []) {
			return [];
		}

		return $this->streamsByNids($nids, $options);
	}

	/**
	 * The page of direct messages addressed to the viewer.
	 *
	 * The recipient join fixes the viewer and the type `dm`, which the unique
	 * index on the recipient rows makes at most one row per post, so the page
	 * needs no `DISTINCT`.
	 *
	 * @return int[]
	 */
	protected function directTimelineNids(ProbeOptions $options): array {
		$page = $this->getStreamNidsSelectSql(false);
		$page->filterType(SocialAppNotification::TYPE);
		$page->paginate($options);
		$this->filterMedia($page, $options);

		// the author is joined for the filters below, not for its columns
		$page->linkToCacheActors('ca', 's.attributed_to_prim', true, false);
		$page->selectDestFollowing('sd', '');
		$page->limitToDest($page->getViewer()->getId(), 'dm', '', 'sd');
		$page->filterHiddenActors();

		return $this->getNidsFromRequest($page);
	}

	/**
	 * Should returns:
	 *  - public message from actorId.
	 *  - followers-only if logged and follower.
	 *
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	private function getTimelineAccount(ProbeOptions $options): array {
		if ($options->getAccountId() === '') {
			return [];
		}

		$nids = $this->accountTimelineNids($options);
		if ($nids === []) {
			return [];
		}

		return $this->streamsByNids($nids, $options);
	}

	/**
	 * The page of one account's posts the viewer may read: its public ones,
	 * or -- for the account reading its own profile -- everything it wrote.
	 *
	 * The recipient join is one row per post when it names the public
	 * collection; for the account itself it names no recipient at all and a
	 * post addressed to several accounts would come back once per row, so
	 * that page is `DISTINCT` and the other is not.
	 *
	 * @return int[]
	 */
	protected function accountTimelineNids(ProbeOptions $options): array {
		$actorId = $options->getAccountId();
		$page = $this->getStreamNidsSelectSql(false);
		$accountIsViewer = ($page->hasViewer() && $page->getViewer()->getId() === $actorId);
		if ($accountIsViewer) {
			$page = $this->getStreamNidsSelectSql(true);
		}

		$page->limitToStatusTypes();
		$page->paginate($options);
		$this->filterMedia($page, $options);
		$page->limitToAttributedTo($actorId, true);

		$page->selectDestFollowing('sd', '');
		$page->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$page->limitToDest($accountIsViewer ? '' : ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$page->linkToCacheActors('ca', 's.attributed_to_prim', true, false);
		$page->filterHiddenActors(SocialCoreQueryBuilder::HIDDEN_DIRECT);

		return $this->getNidsFromRequest($page);
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	private function getTimelineFavourites(ProbeOptions $options): array {
		return $this->getTimelineMarked($options, 'liked');
	}

	/**
	 * The viewer's own marks — liked or bookmarked — newest first.
	 *
	 * Two queries, as the home timeline does it, and for the same reason. Asked
	 * as one, the database drives from the action rows, joins the whole post and
	 * its author to each, and only then sorts the result by post id to take a
	 * page of fifteen: EXPLAIN says "Using temporary; Using filesort" over the
	 * joined rows. Somebody with ten thousand likes therefore pays for ten
	 * thousand wide rows to see the newest fifteen. Deciding the page over one
	 * indexed column first leaves the wide read with exactly the rows it returns.
	 *
	 * @param string $mark the action column that has to be set
	 *
	 * @return Stream[]
	 */
	private function getTimelineMarked(ProbeOptions $options, string $mark): array {
		if (!in_array($mark, ['liked', 'bookmarked'], true)) {
			throw new InvalidArgumentException('unknown mark: ' . $mark);
		}

		// the action join is `(stream_id_prim, actor_id_prim)`, which is unique
		$page = $this->getStreamNidsSelectSql(false);
		$viewer = $page->createNamedParameter($page->prim($page->getViewer()->getId()));
		$page->limitToStatusTypes();
		$page->paginate($options);
		$this->filterMedia($page, $options);
		$page->innerJoin(
			's', CoreRequestBuilder::TABLE_STREAM_ACTIONS, 'sa',
			$page->expr()->andX(
				$page->expr()->eq('sa.stream_id_prim', 's.id_prim'),
				$page->expr()->eq('sa.actor_id_prim', $viewer),
				$page->expr()->eq('sa.' . $mark, $page->createNamedParameter(1))
			)
		);
		$page->filterHiddenActors(SocialCoreQueryBuilder::HIDDEN_DIRECT);

		$nids = $this->getNidsFromRequest($page);
		if ($nids === []) {
			return [];
		}

		$qb = $this->getStreamSelectSql($options->getFormat());
		$qb->andWhere(
			$qb->expr()->in('s.nid', $qb->createNamedParameter($nids, IQueryBuilder::PARAM_INT_ARRAY))
		);
		$qb->orderBy('s.nid', $options->isInverted() ? 'asc' : 'desc');
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction('sa');

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	private function getTimelineBookmarks(ProbeOptions $options): array {
		return $this->getTimelineMarked($options, 'bookmarked');
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	private function getTimelineHashtag(ProbeOptions $options): array {
		$nids = $this->hashtagTimelineNids($options);
		if ($nids === []) {
			return [];
		}

		return $this->streamsByNids($nids, $options);
	}

	/**
	 * The page of posts carrying a hashtag that the viewer may read.
	 *
	 * The tag join and the viewer's recipient join can each match a post more
	 * than once, so the page is `DISTINCT` -- over one integer column, which
	 * is what this is for: it used to be over the whole post.
	 *
	 * @return int[]
	 */
	protected function hashtagTimelineNids(ProbeOptions $options): array {
		$page = $this->getStreamNidsSelectSql(true);
		$page->limitToStatusTypes();
		$page->paginate($options);
		$this->filterMedia($page, $options);

		$page->linkToCacheActors('ca', 's.attributed_to_prim', true, false);
		$page->linkToStreamTags('st', 's.id_prim');
		$page->andWhere($page->exprLimitToDBField('hashtag', $options->getArgument(), true, false, 'st'));

		$page->limitToViewer('sd', 'f', true);
		$page->andWhere($page->expr()->eq('s.attributed_to_prim', 'ca.id_prim'));
		// a hashtag timeline is part of the public square a silenced account loses
		$this->filterSilencedActors($page);

		return $this->getNidsFromRequest($page);
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	private function getTimelineNotifications(ProbeOptions $options): array {
		$wanted = Stream::subTypesOfNotificationTypes($options->getTypes());
		if ($options->getTypes() !== [] && $wanted === []) {
			// every requested type is one we have no notification for, so
			// nothing can match and there is no point in asking the database
			return [];
		}

		// two queries, as the home and public timelines do it and for the same
		// reason. Asked as one, the database carries two full stream column
		// sets, two cached-actor sets and two cached-document sets — several
		// kilobytes a row, `source` and `details` among them — through the
		// `SELECT DISTINCT` the recipient join forces, to choose twenty rows.
		// Every client polls this on a timer, so it is the read where that
		// costs the most.
		// the recipient join fixes the viewer and the type, so it is one row
		$page = $this->getStreamNidsSelectSql(false);
		$actor = $page->getViewer();

		$this->notificationFilters($page, $options, $wanted, $actor->getId(), false);

		$nids = $this->getNidsFromRequest($page);
		if ($nids === []) {
			return [];
		}

		$qb = $this->getStreamSelectSql($options->getFormat());
		$qb->andWhere(
			$qb->expr()->in('s.nid', $qb->createNamedParameter($nids, IQueryBuilder::PARAM_INT_ARRAY))
		);
		$qb->orderBy('s.nid', $options->isInverted() ? 'asc' : 'desc');
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction();
		$qb->leftJoinObjectStatus();

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * What makes a row one of this viewer's notifications.
	 *
	 * Shared by the page-selection query and nothing else — but kept apart
	 * from the hydration so that the two cannot drift into disagreeing about
	 * which notifications exist.
	 *
	 * @param string[] $wanted the notification subtypes asked for
	 * @param bool $select whether the joined author's columns are wanted
	 */
	private function notificationFilters(
		SocialQueryBuilder $qb,
		ProbeOptions $options,
		array $wanted,
		string $actorId,
		bool $select = true,
	): void {
		$qb->limitToType(SocialAppNotification::TYPE);
		$qb->limitToSubTypes($wanted);
		$qb->limitToSubTypes(Stream::subTypesOfNotificationTypes($options->getExcludeTypes()), true);
		$qb->paginate($options);

		$qb->selectDestFollowing('sd', '');
		$qb->limitToDest($actorId, 'notif', '', 'sd');
		$qb->linkToCacheActors('ca', 's.attributed_to_prim', true, $select);

		$qb->filterHiddenActors(SocialCoreQueryBuilder::HIDDEN_NOTIFICATIONS);
		$this->filterMutedConversations($qb, $actorId);
	}

	/**
	 * Drops the notifications a muted thread would produce.
	 *
	 * Mastodon's conversation mute is about being *told*: the thread's posts
	 * stay on every timeline and only the notifications stop, which is what
	 * somebody muting a thread they are in has asked for.
	 *
	 * The mute is stored against the thread's root, so what is excluded here
	 * is every post of those threads — resolved on the way in rather than
	 * joined, because "the thread this post belongs to" is a walk up
	 * `in_reply_to` and there is no portable way to ask a database for it. An
	 * account mutes few threads and a thread is bounded, so this is a small
	 * `NOT IN` and it is skipped entirely by everyone who has muted nothing.
	 */
	private function filterMutedConversations(SocialQueryBuilder $qb, string $actorId): void {
		$roots = $this->conversationsRequest->getMutedRoots($actorId);
		if ($roots === []) {
			return;
		}

		$prims = [];
		foreach ($roots as $root) {
			foreach ($this->conversationsRequest->getThread($root) as $post) {
				$prims[$post['idPrim']] = true;
			}
		}

		if ($prims === []) {
			return;
		}

		$qb->andWhere(
			$qb->expr()->orX(
				$qb->expr()->isNull('s.object_id_prim'),
				$qb->expr()->notIn(
					's.object_id_prim',
					$qb->createNamedParameter(array_keys($prims), IQueryBuilder::PARAM_STR_ARRAY)
				)
			)
		);
	}

	/**
	 * Should return:
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
	 * @deprecated
	 */
	/**
	 * How many notifications the viewer has that they have not seen yet.
	 *
	 * Counted rather than fetched: the badge needs a number, and a viewer who
	 * has been away for a week should not cost a page of hydrated streams to
	 * find that out. The cap keeps the answer cheap — past it the badge says
	 * "lots", which is all anyone reads from a two-digit number anyway.
	 */
	public function countNotificationsSince(Person $actor, int $sinceNid, int $cap = 99): int {
		$qb = $this->getStreamSelectSql();
		$qb->setViewer($actor);

		$qb->limitToType(SocialAppNotification::TYPE);
		$qb->selectDestFollowing('sd', '');
		$qb->limitToDest($actor->getId(), 'notif', '', 'sd');
		$qb->filterHiddenActors(SocialCoreQueryBuilder::HIDDEN_NOTIFICATIONS);

		if ($sinceNid > 0) {
			$qb->andWhere($qb->expr()->gt('s.nid', $qb->createNamedParameter($sinceNid, IQueryBuilder::PARAM_INT)));
		}

		$qb->setMaxResults($cap + 1);

		$cursor = $qb->executeQuery();
		$count = 0;
		while ($cursor->fetch() !== false) {
			$count++;
		}
		$cursor->closeCursor();

		return $count;
	}

	/**
	 * Should return:
	 *  * All local public/federated posts
	 *
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	/**
	 * A silenced account keeps its followers and loses the public square.
	 *
	 * The list is small — a moderator acts rarely — so it is read and passed
	 * as a literal set rather than joined against.
	 */
	private function filterSilencedActors(SocialQueryBuilder $qb): void {
		$silenced = $this->moderationRequest->getActorIdsAt(Moderation::SILENCE);
		if ($silenced !== []) {
			$prims = array_map(fn (string $id): string => $qb->prim($id), $silenced);
			$qb->andWhere(
				$qb->expr()->notIn(
					's.attributed_to_prim',
					$qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
				)
			);
		}

		$this->filterSilencedInstances($qb);
	}

	/**
	 * Keeps a silenced instance out of the timeline this query is building.
	 *
	 * The middle tier of a domain block: an account there stays readable by
	 * whoever follows it and stops appearing in the public and global
	 * timelines, which is the whole difference between a nuisance and a menace.
	 *
	 * Matched on the author's id rather than on a host column, because there is
	 * none: an actor id begins with the scheme and host, so a domain and
	 * everything under it is a prefix — `https://evil.test/` and
	 * `%.evil.test/`. A block reads subdomains the same way, and anything less
	 * would last as long as it takes to point a wildcard record at the same
	 * host. The list is an admin-written handful, so one clause each is cheap;
	 * `LIKE` on the id is not indexed, which is why this runs only on the two
	 * timelines that need it.
	 */
	private function filterSilencedInstances(SocialQueryBuilder $qb): void {
		$silenced = $this->fediverseService->getSilencedAddresses();
		if ($silenced === []) {
			return;
		}

		foreach ($silenced as $host) {
			$host = strtolower(trim($host));
			if ($host === '') {
				continue;
			}

			$qb->andWhere($qb->expr()->andX(
				$qb->expr()->notLike(
					's.attributed_to',
					$qb->createNamedParameter('%://' . $this->escapeLike($host) . '/%')
				),
				$qb->expr()->notLike(
					's.attributed_to',
					$qb->createNamedParameter('%.' . $this->escapeLike($host) . '/%')
				)
			));
		}
	}

	/** `%`, `_` and the escape itself are literals in a hostname. */
	private function escapeLike(string $value): string {
		return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
	}

	private function getTimelinePublic(ProbeOptions $options): array {
		// the recipient join fixes the actor (the public collection) and the
		// type, which the unique index makes at most one row
		$page = $this->getStreamNidsSelectSql(false);
		$page->paginate($options);
		$this->filterMedia($page, $options);

		// `local=true` is this instance's own posts, `remote=true` every other
		// instance's: Mastodon's two ways of narrowing the same timeline
		$origin = $options->originLimit();
		if ($origin !== null) {
			$page->limitToLocal($origin);
		}
		$page->limitToStatusTypes();
		$page->selectDestFollowing('sd', '');
		$page->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$page->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', 'to', 'sd');
		$page->filterHiddenActors();
		$this->filterSilencedActors($page);

		$nids = $this->getNidsFromRequest($page);
		if ($nids === []) {
			return [];
		}

		$qb = $this->getStreamSelectSql($options->getFormat());
		$qb->andWhere(
			$qb->expr()->in('s.nid', $qb->createNamedParameter($nids, IQueryBuilder::PARAM_INT_ARRAY))
		);
		$qb->orderBy('s.nid', $options->isInverted() ? 'asc' : 'desc');
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction();

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * When each of an author's public posts was published, since a point in
	 * time.
	 *
	 * Only the timestamps: what the profile's little activity chart needs is
	 * how many posts fell in each week, and hydrating the posts to count them
	 * would read every column of every note to throw all of it away. Public
	 * posts only — a chart drawn from what the viewer happens to be allowed to
	 * see would be a different chart per viewer, and a chart that counted
	 * posts the viewer cannot see would leak that they exist.
	 *
	 * Boosts are left out by `limitToStatusTypes()`: a boost is something the
	 * account did, not something it wrote.
	 *
	 * @param string $actorId the author
	 * @param int $since unix time to start at
	 * @param int $limit a ceiling, so a prolific account cannot make this
	 *                   query grow without bound
	 * @return list<int> publication times, newest first
	 */
	public function publishedTimesByAuthor(string $actorId, int $since, int $limit = 2000): array {
		if ($actorId === '' || $limit < 1) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$date = new DateTime();
		$date->setTimestamp($since);

		$qb->select('s.published_time')
			->from(self::TABLE_STREAM, 's')
			->where($expr->gte(
				's.published_time', $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE)
			))
			->orderBy('s.published_time', 'desc')
			->setMaxResults($limit);

		$qb->setDefaultSelectAlias('s');
		$qb->limitToAttributedTo($actorId, true);
		$qb->limitToStatusTypes();

		// the recipients are where "public" is recorded, the same join the
		// author's public timeline uses
		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$times = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$time = (int)strtotime((string)$data['published_time']);
			if ($time > 0) {
				$times[] = $time;
			}
		}
		$cursor->closeCursor();

		return $times;
	}

	/**
	 * An author's own posts published inside a window of time.
	 *
	 * Used to look the reader up their own past: unlike the profile charts
	 * above, this is only ever called for the caller's own account, so it is
	 * not held to public posts — somebody looking back at their own year
	 * should see what they actually wrote, followers-only posts included.
	 * Every caller must therefore check that the actor is the viewer.
	 *
	 * @param string $actorId the author
	 * @param int $from unix time, inclusive
	 * @param int $until unix time, exclusive
	 * @param int $limit how many to return at most
	 * @param int $format how the posts are to be exported
	 * @return Stream[] newest first
	 */
	public function getByAuthorBetween(
		string $actorId,
		int $from,
		int $until,
		int $limit = 10,
		int $format = ACore::FORMAT_ACTIVITYPUB,
	): array {
		if ($actorId === '' || $limit < 1 || $until <= $from) {
			return [];
		}

		$fromDate = new DateTime();
		$fromDate->setTimestamp($from);
		$untilDate = new DateTime();
		$untilDate->setTimestamp($until);

		$qb = $this->getStreamSelectSql($format);
		$qb->limitToAttributedTo($actorId, true);
		$qb->limitToStatusTypes();

		$expr = $qb->expr();
		$qb->andWhere($expr->gte(
			's.published_time', $qb->createNamedParameter($fromDate, IQueryBuilder::PARAM_DATE)
		));
		$qb->andWhere($expr->lt(
			's.published_time', $qb->createNamedParameter($untilDate, IQueryBuilder::PARAM_DATE)
		));

		// a reply is an answer to somebody else's post and reads as a
		// fragment out of its thread, which is not much of a memory
		$qb->limitToDBFieldEmpty('in_reply_to');

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction();

		$qb->orderBy('s.published_time', 'desc');
		$qb->setMaxResults($limit);

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * The hashtags an author uses most, over their public posts since a point
	 * in time.
	 *
	 * Grouped in SQL rather than walked in PHP: unlike the per-post details
	 * blob, a hashtag is a row of its own in `social_stream_tag`, so the three
	 * databases can all count them the same way.
	 *
	 * @param string $actorId the author
	 * @param int $since unix time to start at
	 * @param int $limit how many to name
	 * @return list<array{name: string, count: int}> most used first
	 */
	public function topHashtagsByAuthor(string $actorId, int $since, int $limit = 3): array {
		if ($actorId === '' || $limit < 1) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$date = new DateTime();
		$date->setTimestamp($since);

		$qb->select('st.hashtag')
			->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_STREAM_TAGS, 'st')
			->innerJoin('st', self::TABLE_STREAM, 's', $expr->eq('s.id_prim', 'st.stream_id'))
			->where($expr->eq('s.attributed_to_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($expr->gte(
				's.published_time', $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE)
			))
			->groupBy('st.hashtag')
			->orderBy('total', 'desc')
			->addOrderBy('st.hashtag', 'asc')
			->setMaxResults($limit);

		$qb->setDefaultSelectAlias('s');
		$qb->limitToStatusTypes();

		$tags = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$name = (string)$data['hashtag'];
			if ($name === '') {
				continue;
			}
			$tags[] = ['name' => $name, 'count' => (int)$data['total']];
		}
		$cursor->closeCursor();

		return $tags;
	}

	/**
	 * How often each hashtag was used since a point in time.
	 *
	 * This is what the trends cron needs, and all it needs. It used to hydrate
	 * the posts themselves — every column of every note, plus its action row —
	 * and count the tags in PHP, bounded to a sample of the most recent
	 * thousand notes; on a busy instance all five windows saw the same
	 * thousand notes and every period therefore reported the same count.
	 *
	 * @return array<string, int> hashtag => how many posts used it
	 */
	public function countHashtagsSince(int $since): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$date = new DateTime();
		$date->setTimestamp($since);

		$qb->select('st.hashtag')
			->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_STREAM_TAGS, 'st')
			->innerJoin('st', self::TABLE_STREAM, 's', $expr->eq('s.id_prim', 'st.stream_id'))
			->where($expr->gte(
				's.published_time', $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE)
			))
			->groupBy('st.hashtag');

		$counts = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$counts[(string)$data['hashtag']] = (int)$data['total'];
		}
		$cursor->closeCursor();

		return $counts;
	}

	/**
	 * The public posts taken at one place, newest first.
	 *
	 * Public only, whoever asks: a place page is a public page, and a
	 * followers-only post's location is as private as the post. Keyset by
	 * `nid`, like every other list here.
	 *
	 * @return Stream[]
	 */
	public function getPublicByPlace(int $placeId, int $limit = 20, int $maxId = 0): array {
		if ($placeId < 1 || $limit < 1) {
			return [];
		}

		$qb = $this->getStreamSelectSql(ACore::FORMAT_LOCAL);
		$qb->limitToStatusTypes();
		$expr = $qb->expr();
		$qb->andWhere($expr->eq('s.place_id', $qb->createNamedParameter($placeId, IQueryBuilder::PARAM_INT)));
		$qb->andWhere($expr->eq('s.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC)));
		// a reply is a fragment of somebody else's thread, not a picture of the place
		$qb->limitToDBFieldEmpty('in_reply_to');
		if ($maxId > 0) {
			$qb->andWhere($expr->lt('s.nid', $qb->createNamedParameter($maxId, IQueryBuilder::PARAM_INT)));
		}

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction();
		$qb->orderBy('s.nid', 'desc');
		$qb->setMaxResults($limit);

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * The direct messages between the viewer and one other account, as one
	 * thread.
	 *
	 * A direct message writes a `dm` destination row for every party to it,
	 * its author included (`StreamDestRequest::generateStreamDirect()`), so
	 * the thread is every direct post that has a row for both of them —
	 * whichever of the two wrote it. Keyset-paged on `nid` like every other
	 * timeline here.
	 *
	 * @return Stream[] newest first, or oldest first when paging forward with `minId`
	 */
	public function directBetween(Person $viewer, string $otherId, int $limit = 20, int $maxId = 0, int $minId = 0): array {
		if ($otherId === '' || $limit < 1) {
			return [];
		}

		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_LOCAL)
			->setLimit($limit);
		if ($maxId > 0) {
			$options->setMaxId($maxId);
		}
		if ($minId > 0) {
			$options->setMinId($minId);
		}

		$this->setViewer($viewer);
		$page = $this->getStreamNidsSelectSql(false);
		$page->limitToStatusTypes();
		$page->paginate($options);
		$page->limitToDBField('visibility', Stream::TYPE_DIRECT, true, 's');

		$page->selectDestFollowing('sd1', '');
		$page->from(self::TABLE_STREAM_DEST, 'sd2');
		$page->andWhere($page->exprLimitToDest($viewer->getId(), 'dm', '', 'sd1'));
		$page->andWhere($page->exprLimitToDest($otherId, 'dm', '', 'sd2'));

		$nids = $this->getNidsFromRequest($page);
		if ($nids === []) {
			return [];
		}

		return $this->streamsByNids($nids, $options);
	}

	/**
	 * Who boosted each of these posts.
	 *
	 * One query for the whole set rather than one per post: the statistics
	 * page asks about a month of posts at once, and a round trip per post to
	 * fill in one column is a page that gets slower the more an account posts.
	 *
	 * The same account boosting the same post twice is one audience, so the
	 * actors come back deduplicated per post.
	 *
	 * @param string[] $ids the posts, by id
	 * @param int $limit how many Announce rows to read at most
	 * @return array<string, list<string>> post id => the actors that boosted it
	 */
	public function boostersOf(array $ids, int $limit = 5000): array {
		if ($ids === [] || $limit < 1) {
			return [];
		}

		$qb = $this->getQueryBuilder();

		$byPrim = [];
		foreach ($ids as $id) {
			$prim = $qb->prim($id);
			if ($prim !== '') {
				$byPrim[$prim] = $id;
			}
		}

		if ($byPrim === []) {
			return [];
		}

		$expr = $qb->expr();
		$qb->select('s.object_id_prim', 's.attributed_to')
			->from(self::TABLE_STREAM, 's')
			->where($expr->eq('s.type', $qb->createNamedParameter(Announce::TYPE)))
			->andWhere($expr->in(
				's.object_id_prim',
				$qb->createNamedParameter(array_keys($byPrim), IQueryBuilder::PARAM_STR_ARRAY)
			))
			->setMaxResults($limit);

		$boosters = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$id = $byPrim[(string)$data['object_id_prim']] ?? '';
			$actor = (string)$data['attributed_to'];
			if ($id === '' || $actor === '') {
				continue;
			}
			$boosters[$id][$actor] = $actor;
		}
		$cursor->closeCursor();

		return array_map(static fn (array $actors): array => array_values($actors), $boosters);
	}

	/**
	 * Removes a post and everything that hangs off it.
	 *
	 * A post is not one row: its recipients (which is what puts it in a
	 * timeline), the interaction flags on it, its hashtags, its link card, the
	 * Like/Announce rows pointing at it and its cached attachments all key on
	 * it. Deleting only the `social_stream` row left every one of those behind
	 * in five tables plus the files on disk, for good — nothing else ever
	 * looks at them again.
	 *
	 * @param string $type when given, the post is only removed if it is of
	 *                     that type — and then nothing else is touched either
	 */
	public function deleteById(string $id, string $type = '') {
		$qb = $this->getStreamDeleteSql();
		$prim = $this->streamPrim($qb, $id);
		if ($prim === '') {
			return;
		}

		$qb->limitToIdPrim($prim);
		if ($type !== '') {
			$qb->limitToType($type);
		}

		$deleted = $qb->executeStatement();
		if ($type !== '' && $deleted === 0) {
			// a guarded delete that matched nothing: the post is of another
			// type and still exists, so its related rows are still in use
			return;
		}

		$this->deleteRelatedTo([$prim]);
	}

	/**
	 * Removes every post of an author, and everything that hangs off each of
	 * them. Done in batches: an account at scale has more posts than the
	 * cascade wants to name in one IN () list.
	 */
	public function deleteByAuthor(string $actorId) {
		while (true) {
			$prims = $this->getIdPrimsByAuthor($actorId, self::DELETE_BATCH);
			if ($prims === []) {
				return;
			}

			$this->deleteRelatedTo($prims);

			$qb = $this->getStreamDeleteSql();
			$qb->andWhere($qb->expr()->in(
				'id_prim',
				$qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
			));
			if ($qb->executeStatement() === 0) {
				// nothing was removed, so the next pass would select the very
				// same rows: stop rather than spin
				return;
			}
		}
	}

	/**
	 * The authors of one instance that still have something stored here.
	 *
	 * Distinct authors rather than posts: the caller deletes an author's posts
	 * with `deleteByAuthor()`, which is already batched, so this only has to
	 * name who is left. Once an author's posts are gone they are not named
	 * again, which is what lets a purge resume where it stopped.
	 *
	 * @return string[] actor ids
	 *
	 * @throws InvalidResourceException the domain is not one
	 */
	public function getAuthorsFromDomain(string $domain, int $limit = 100): array {
		$qb = $this->getQueryBuilder();
		$qb->selectDistinct('s.attributed_to')
			->from(self::TABLE_STREAM, 's')
			->where(DomainBlocksRequestBuilder::onDomain($qb, 's.attributed_to', $domain))
			->setMaxResults($limit);

		$cursor = $qb->executeQuery();
		$authors = array_map(
			static fn (array $row): string => (string)$row['attributed_to'], $cursor->fetchAll()
		);
		$cursor->closeCursor();

		return $authors;
	}

	/**
	 * @return string[] id_prim of the posts of an author
	 */
	private function getIdPrimsByAuthor(string $actorId, int $limit): array {
		$qb = $this->getQueryBuilder();
		$qb->select('s.id_prim')
			->from(self::TABLE_STREAM, 's')
			->where($qb->expr()->eq(
				's.attributed_to_prim', $qb->createNamedParameter($qb->prim($actorId))
			))
			->setMaxResults($limit);

		$cursor = $qb->executeQuery();
		$prims = array_map(static fn (array $row): string => (string)$row['id_prim'], $cursor->fetchAll());
		$cursor->closeCursor();

		return $prims;
	}

	/**
	 * Removes the rows and the cached files that belong to a set of posts,
	 * addressed by their id_prim. The single place that knows what "related to
	 * a post" means.
	 *
	 * @param string[] $prims
	 *
	 * @return int how many cached attachment rows were removed
	 */
	public function deleteRelatedTo(array $prims): int {
		if ($prims === []) {
			return 0;
		}

		$documents = $this->deleteDocumentsOf($prims);

		foreach ([
			// dest and tag rows hold the prim in a column that is not named for it
			[self::TABLE_STREAM_DEST, 'stream_id'],
			[self::TABLE_STREAM_TAGS, 'stream_id'],
			[self::TABLE_STREAM_ACTIONS, 'stream_id_prim'],
			[self::TABLE_STREAM_CARDS, 'stream_id_prim'],
			[self::TABLE_STATUS_REVISIONS, 'stream_id_prim'],
			// the Like and Announce activities pointing at the post
			[self::TABLE_ACTIONS, 'object_id_prim'],
			// and the emoji reactions on it, which are a table of their own
			[self::TABLE_REACTIONS, 'object_id_prim'],
			// and its place in any album its author put it in: a collection
			// entry pointing at a post that is gone would draw a gap
			[self::TABLE_COLLECTION_ITEMS, 'stream_id_prim'],
			// and who opened it, which is a row about a post that no longer
			// exists and that nothing will ever read again
			[self::TABLE_STREAM_VIEWS, 'stream_id_prim'],
			// and the people named in its pictures
			[self::TABLE_MEDIA_TAGS, 'stream_id_prim'],
		] as [$table, $field]) {
			$qb = $this->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in(
					$field, $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
				));
			$qb->executeStatement();
		}

		return $documents;
	}

	/**
	 * The cached attachments of a set of posts: the files first, then the rows
	 * that name them — a row without its file is recoverable, a file without
	 * its row is not.
	 *
	 * @param string[] $prims
	 */
	private function deleteDocumentsOf(array $prims): int {
		$qb = $this->getQueryBuilder();
		$qb->select('id_prim', 'local_copy', 'resized_copy')
			->from(self::TABLE_CACHE_DOCUMENTS)
			->where($qb->expr()->in(
				'parent_id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
			));

		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();
		if ($rows === []) {
			return 0;
		}

		foreach ($rows as $row) {
			$this->cacheDocumentService->removeFromCache((string)$row['local_copy']);
			$this->cacheDocumentService->removeFromCache((string)$row['resized_copy']);
		}

		$delete = $this->getQueryBuilder();
		$delete->delete(self::TABLE_CACHE_DOCUMENTS)
			->where($delete->expr()->in('id_prim', $delete->createNamedParameter(
				array_map(static fn (array $row): string => (string)$row['id_prim'], $rows),
				IQueryBuilder::PARAM_STR_ARRAY
			)));

		return $delete->executeStatement();
	}

	/**
	 * A post is addressed either by its uri or, from the dest table, by the
	 * prim it is stored under there — the two are not distinguishable by the
	 * signature, and reading an already-hashed id as a uri hashes it twice and
	 * silently matches nothing.
	 */
	private function streamPrim(SocialQueryBuilder $qb, string $id): string {
		$prim = $qb->prim($id);
		if ($prim !== '') {
			return $prim;
		}

		return (preg_match('/^[0-9a-f]{32}$/', $id) === 1) ? $id : '';
	}

	/**
	 * @param string $actorId
	 */
	public function updateAuthor(string $actorId, string $newId) {
		$qb = $this->getStreamUpdateSql();
		$qb->set('attributed_to', $qb->createNamedParameter($newId))
			->set('attributed_to_prim', $qb->createNamedParameter($qb->prim($newId)));
		$qb->limitToAttributedTo($actorId, true);

		$qb->executeStatement();
	}

	/**
	 * The five fields that used to live only inside the stored wire object,
	 * written to the columns Version1000Date20260912000007 added.
	 *
	 * One helper for both write paths on purpose: an insert and an edit have to
	 * agree about what the columns hold, and `sensitive` is the reminder of
	 * what happens when only one of the two writes a field.
	 *
	 * `updated` is bound as a date in UTC. Doctrine renders a DateTime in
	 * whatever zone the object carries, so a remote `updated` of
	 * `2026-09-12T10:00:00+02:00` would otherwise be stored as 10:00 and read
	 * back as 10:00 UTC — the right text for the wrong instant.
	 */
	private function setPostFields(IQueryBuilder $qb, Stream $stream, bool $insert): void {
		$values = [
			'tags' => [json_encode($stream->getTags(), JSON_UNESCAPED_SLASHES), IQueryBuilder::PARAM_STR],
			'language' => [$stream->getLanguage(), IQueryBuilder::PARAM_STR],
			'quote' => [$stream->getQuote(), IQueryBuilder::PARAM_STR],
			'quote_authorization' => [$stream->getQuoteAuthorization(), IQueryBuilder::PARAM_STR],
			'updated' => [$this->updatedAsDate($stream->getUpdated()), IQueryBuilder::PARAM_DATE],
		];

		foreach ($values as $column => [$value, $type]) {
			if ($insert) {
				$qb->setValue($column, $qb->createNamedParameter($value, $type));
			} else {
				$qb->set($column, $qb->createNamedParameter($value, $type));
			}
		}
	}

	/** The ActivityPub `updated` of a post as a UTC date, or null for one never edited. */
	private function updatedAsDate(string $updated): ?DateTime {
		if ($updated === '') {
			return null;
		}

		try {
			return (new DateTime($updated))->setTimezone(new DateTimeZone('UTC'));
		} catch (Exception) {
			return null;
		}
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

		if ($stream->getNid() === 0) {
			$stream->setNid(
				$stream->getPublishedTime() * self::NID_LIMIT + random_int(1, self::NID_LIMIT)
			);
		}

		$qb = $this->getStreamInsertSql();
		$qb->setValue('nid', $qb->createNamedParameter($stream->getNid()))
			->setValue('id', $qb->createNamedParameter($stream->getId()))
			->setValue('visibility', $qb->createNamedParameter($stream->getVisibility()))
			->setValue('sensitive', $qb->createNamedParameter($stream->isSensitive() ? 1 : 0))
			->setValue('place_id', $qb->createNamedParameter($stream->getPlaceId()))
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
					json_encode($stream->getBccArray(), JSON_UNESCAPED_SLASHES)
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

		$this->setPostFields($qb, $stream, true);

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

	public function getRelatedToActor(string $actorId) {
	}

	/** How much of a thread one context request returns. */
	public const MAX_DESCENDANTS = 200;
	/** How many levels below a post one context request walks. */
	public const MAX_DESCENDANT_DEPTH = 20;

	/**
	 * The whole conversation under a post: its replies, the replies to those,
	 * and so on — what `/statuses/{id}/context` calls the descendants. Used to
	 * return the direct replies only, so a thread three messages deep showed as
	 * one reply with nothing under it.
	 *
	 * Bounded in depth and in count (Mastodon bounds the same walk), filtered
	 * for the viewer at every level, and returned depth first with siblings
	 * oldest first, so each reply follows what it answers.
	 *
	 * @return Stream[]
	 */
	public function getDescendants(string $id): array {
		$byParent = [];
		$parents = [$id];
		$count = 0;
		for ($depth = 0; $depth < self::MAX_DESCENDANT_DEPTH && $parents !== []; $depth++) {
			$remaining = self::MAX_DESCENDANTS - $count;
			if ($remaining <= 0) {
				break;
			}

			$level = $this->getRepliesTo($parents, $remaining);
			$count += count($level);
			$parents = [];
			foreach ($level as $reply) {
				$byParent[$reply->getInReplyTo()][] = $reply;
				$parents[] = $reply->getId();
			}
		}

		$thread = [];
		$this->flattenThread($id, $byParent, $thread);
		// a row was only ever selected as the child of a row above it, so this is
		// empty unless a stored in_reply_to differs from its parent's id in spelling
		foreach ($byParent as $unplaced) {
			array_push($thread, ...$unplaced);
		}

		return $thread;
	}

	/**
	 * @param array<string, Stream[]> $byParent
	 * @param Stream[] $thread
	 */
	private function flattenThread(string $parent, array &$byParent, array &$thread): void {
		foreach ($byParent[$parent] ?? [] as $reply) {
			$thread[] = $reply;
			$this->flattenThread($reply->getId(), $byParent, $thread);
		}
		unset($byParent[$parent]);
	}

	/**
	 * One level of a thread: the direct replies to a set of posts, as the
	 * viewer may see them, oldest first.
	 *
	 * @param string[] $ids
	 *
	 * @return Stream[]
	 */
	protected function getRepliesTo(array $ids, int $limit): array {
		$qb = $this->getStreamSelectSql(ACore::FORMAT_LOCAL);

		$qb->filterType(SocialAppNotification::TYPE);
		$qb->limitToViewer('sd', 'f', true);
		$qb->limitToDBFieldArray(
			'in_reply_to_prim',
			array_map(static fn (string $id): string => $qb->prim($id), $ids)
		);
		// a thread is read by anyone, logged in or not, and every row comes
		// back fully hydrated: the context of a post that thousands replied to
		// is not something to hand to PHP whole
		$qb->setMaxResults($limit);
		$qb->orderBy('s.published_time', 'asc');

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * The boosts of a post and the replies to it, each with its author joined
	 * in: the actors a Delete of the post has to reach beyond the author's own
	 * followers, because each of them carried the post to followers of theirs.
	 *
	 * @return Stream[]
	 */
	public function getAnnouncesAndRepliesTo(string $id, int $limit = 500): array {
		$qb = $this->getStreamSelectSql();
		$prim = $qb->prim($id);
		if ($prim === '') {
			return [];
		}

		$expr = $qb->expr();
		$qb->andWhere(
			$expr->orX(
				$expr->andX(
					$qb->exprLimitToDBField('type', Announce::TYPE),
					$qb->exprLimitToDBField('object_id_prim', $prim)
				),
				$qb->exprLimitToDBField('in_reply_to_prim', $prim)
			)
		);
		$qb->setMaxResults($limit);
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		return $this->getStreamsFromRequest($qb);
	}
}
