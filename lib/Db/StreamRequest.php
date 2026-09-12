<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
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
	) {
		parent::__construct($connection, $logger, $urlGenerator, $configService, $miscService);
	}

	public function save(Stream $stream): void {
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

	public function updateCache(Stream $stream, Cache $cache): void {
		$qb = $this->getStreamUpdateSql();
		$qb->set('cache', $qb->createNamedParameter(json_encode($cache, JSON_UNESCAPED_SLASHES)));

		$qb->limitToIdPrim($qb->prim($stream->getId()));

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
	 * Applies `only_media` when the caller asked for it.
	 *
	 * The option has been parsed off the request since the hashtag timeline
	 * gained it and was never applied to a query, so `only_media=true` quietly
	 * returned everything. Every timeline that can carry media runs it through
	 * here, so the dedicated photo timeline and a client asking Mastodon's
	 * question of any other list get the same answer.
	 */
	private function filterMedia(SocialQueryBuilder $qb, ProbeOptions $options): void {
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
		$this->homeTimelineFilters($page, $options);

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
	private function homeTimelineFilters(SocialQueryBuilder $qb, ProbeOptions $options): void {
		$qb->filterType(SocialAppNotification::TYPE);
		$qb->paginate($options);
		$this->filterMedia($qb, $options);
		$qb->limitToViewer('sd', 'f', false);
		// a filter, not a join: it constrains on the follow's type
		$this->timelineHomeLinkCacheActor($qb, 'ca', 'f');
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
		$qb = $this->getStreamSelectSql($options->getFormat());

		$qb->filterType(SocialAppNotification::TYPE);
		$qb->paginate($options);
		$this->filterMedia($qb, $options);

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		$viewer = $qb->getViewer();
		$qb->selectDestFollowing('sd', '');
		$qb->limitToDest($viewer->getId(), 'dm', '', 'sd');

		$qb->filterHiddenActors();

		return $this->getStreamsFromRequest($qb);
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
		$qb = $this->getStreamSelectSql($options->getFormat());

		$qb->limitToStatusTypes();
		$qb->paginate($options);
		$this->filterMedia($qb, $options);

		$actorId = $options->getAccountId();
		if ($actorId === '') {
			return [];
		}

		$qb->limitToAttributedTo($actorId, true);

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$accountIsViewer = ($qb->hasViewer() && $qb->getViewer()->getId() === $actorId);
		$qb->limitToDest($accountIsViewer ? '' : ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction();

		$qb->filterHiddenActors(SocialCoreQueryBuilder::HIDDEN_DIRECT);

		return $this->getStreamsFromRequest($qb);
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

		$page = $this->getStreamNidsSelectSql();
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
		$qb = $this->getStreamSelectSql($options->getFormat());
		$qb->limitToStatusTypes();
		$qb->paginate($options);
		$this->filterMedia($qb, $options);

		$expr = $qb->expr();
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->linkToStreamTags('st', 's.id_prim');
		$qb->andWhere($qb->exprLimitToDBField('hashtag', $options->getArgument(), true, false, 'st'));

		$qb->limitToViewer('sd', 'f', true);
		$qb->andWhere($expr->eq('s.attributed_to_prim', 'ca.id_prim'));
		// a hashtag timeline is part of the public square a silenced account loses
		$this->filterSilencedActors($qb);

		$qb->leftJoinStreamAction('sa');

		return $this->getStreamsFromRequest($qb);
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

		$qb = $this->getStreamSelectSql($options->getFormat());
		$actor = $qb->getViewer();

		$qb->limitToType(SocialAppNotification::TYPE);
		$qb->limitToSubTypes($wanted);
		$qb->limitToSubTypes(Stream::subTypesOfNotificationTypes($options->getExcludeTypes()), true);
		$qb->paginate($options);

		$qb->selectDestFollowing('sd', '');
		$qb->limitToDest($actor->getId(), 'notif', '', 'sd');
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction();
		$qb->leftJoinObjectStatus();

		$qb->filterHiddenActors(SocialCoreQueryBuilder::HIDDEN_NOTIFICATIONS);

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * Should return:
	 *  * Own posts,
	 *  * Followed accounts
	 *
	 * @param int $since
	 * @param int $limit
	 * @param int $format
	 *
	 * @return Stream[]
	 * @throws DateTimeException
	 * @deprecated - use getTimelineHome()
	 */
	public function getTimelineHome_dep(
		int $since = 0, int $limit = 5, int $format = Stream::FORMAT_ACTIVITYPUB,
	): array {
		$qb = $this->getStreamSelectSql($format);

		$qb->filterType(SocialAppNotification::TYPE);
		$qb->limitPaginate($since, $limit);

		$qb->limitToViewer('sd', 'f', false);
		$this->timelineHomeLinkCacheActor($qb, 'ca', 'f');

		$qb->leftJoinStreamAction('sa');
		$qb->filterDuplicate();

		return $this->getStreamsFromRequest($qb);
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

	public function getTimelineNotifications_dep(int $since = 0, int $limit = 5): array {
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
	 * Should return:
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
	public function getTimelineAccount_dep(string $actorId, int $since = 0, int $limit = 5): array {
		$qb = $this->getStreamSelectSql();
		$qb->limitPaginate($since, $limit);

		$qb->limitToAttributedTo($actorId);

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$accountIsViewer = ($qb->hasViewer() && $qb->getViewer()->getId() === $actorId);
		$qb->limitToDest($accountIsViewer ? '' : ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction();

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * Should return:
	 *  * Private message.
	 *  - group messages. (not yet)
	 *
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Stream[]
	 * @throws DateTimeException
	 */
	public function getTimelineDirect_dep(int $since = 0, int $limit = 5): array {
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
		$page = $this->getStreamNidsSelectSql();
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
	 * Should returns:
	 *  * All local public/federated posts
	 *
	 * @param int $since
	 * @param int $limit
	 * @param bool $localOnly
	 *
	 * @return Stream[]
	 * @throws DateTimeException
	 * @deprecated - use getTimelinePublic()
	 */
	public function getTimelineGlobal_dep(int $since = 0, int $limit = 5, bool $localOnly = true,
	): array {
		$qb = $this->getStreamSelectSql();
		$qb->limitPaginate($since, $limit);

		$qb->limitToLocal($localOnly);
		$qb->limitToStatusTypes();

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction();

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
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

		$qb->limitToStatusTypes();
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
	 * Should return:
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
