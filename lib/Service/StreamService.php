<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\ActivityPub\OrderedCollectionPage;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\InstancePath;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class StreamService {
	use TArrayTools;

	/** How far up a thread one context request walks; Mastodon's cap. */
	private const ANCESTOR_LIMIT = 40;

	/**
	 * How many entries of a remote outbox page a single sync walks.
	 *
	 * The page comes from a server the caller names and is bounded only by the
	 * body size cap, so a page of minimal Notes carries tens of thousands of
	 * entries — each one a lookup and an INSERT. `ApiController::fetchRemoteCollection`
	 * bounds its own walk over a remote page the same way.
	 */
	private const SYNC_ITEM_LIMIT = 20;

	public function __construct(
		private IUrlGenerator $urlGenerator,
		private StreamRequest $streamRequest,
		private ActivityService $activityService,
		private CacheActorService $cacheActorService,
		private ConfigService $configService,
		private CurlService $curlService,
		private LinkPreviewService $linkPreviewService,
		private EmojiService $emojiService,
		private LoggerInterface $logger,
		private PlaceService $placeService,
	) {
	}

	/**
	 * @param Person $viewer
	 */
	public function setViewer(Person $viewer) {
		$this->streamRequest->setViewer($viewer);
	}

	/**
	 * @param ACore $stream
	 * @param Person $actor
	 * @param string $type
	 *
	 * @throws SocialAppConfigException
	 * @throws Exception
	 */
	public function assignItem(Acore $stream, Person $actor, string $type) {
		$stream->setId($this->configService->generateId('@' . $actor->getPreferredUsername()));
		$stream->setPublished(date('c'));

		$this->setRecipient($stream, $actor, $type);
		$stream->setLocal(true);

		if ($stream instanceof Stream) {
			$this->assignStream($stream);
		}
	}

	/**
	 * @param Stream $stream
	 *
	 * @throws Exception
	 */
	public function assignStream(Stream $stream) {
		$stream->convertPublished();
	}

	/**
	 * @param ACore $stream
	 * @param Person $actor
	 * @param string $type
	 */
	private function setRecipient(ACore $stream, Person $actor, string $type) {
		switch ($type) {
			case Stream::TYPE_UNLISTED:
				$stream->setTo($actor->getFollowers());
				$stream->addInstancePath(
					new InstancePath(
						$actor->getId(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				$stream->addCc(ACore::CONTEXT_PUBLIC);
				break;

			case Stream::TYPE_FOLLOWERS:
				$stream->setTo($actor->getFollowers());
				$stream->addInstancePath(
					new InstancePath(
						$actor->getId(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				break;

			case Stream::TYPE_ANNOUNCE:
				$stream->addInstancePath(
					new InstancePath(
						$actor->getId(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				$stream->addCc($actor->getFollowers());
				break;

			case Stream::TYPE_DIRECT:
				break;

			case Stream::TYPE_PUBLIC:
				$stream->setTo(ACore::CONTEXT_PUBLIC);
				$stream->addCc($actor->getFollowers());
				$stream->addInstancePath(
					new InstancePath(
						$actor->getId(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				break;

			default:
				// Fail closed. `public` is spelled out above, so anything
				// arriving here is a visibility this app does not know, and
				// addressing it to the public collection — which is what used
				// to happen — publishes a post its author never meant to make
				// public. Mastodon's `private` is translated to `followers`
				// long before this point; see `Stream::visibilityFromClient()`.
				$this->logger->warning(
					'refusing to address a stream with an unknown visibility; kept private',
					['visibility' => $type, 'stream' => $stream->getId()]
				);
				break;
		}
	}

	/**
	 * Classify a stream by who can see it, for `DetailsService`.
	 */
	public function detectType(Stream $stream): void {
		if (in_array(ACore::CONTEXT_PUBLIC, $stream->getToAll())) {
			$stream->setTimeline(Stream::TYPE_PUBLIC);

			return;
		}

		if (in_array(ACore::CONTEXT_PUBLIC, $stream->getCcArray())) {
			$stream->setTimeline(Stream::TYPE_UNLISTED);

			return;
		}

		try {
			$actor = $this->cacheActorService->getFromId($stream->getAttributedTo());
		} catch (Exception $e) {
			return;
		}

		$followers = $actor->getFollowers();
		$recipients = array_merge($stream->getToAll(), $stream->getCcArray());

		$stream->setTimeline(
			($followers !== '' && in_array($followers, $recipients, true))
				? Stream::TYPE_FOLLOWERS
				: Stream::TYPE_DIRECT
		);
	}

	/**
	 * @param Stream $stream
	 * @param string $type
	 * @param string $account
	 */
	public function addRecipient(Stream $stream, string $type, string $account) {
		if ($account === '') {
			return;
		}

		try {
			$actor = $this->cacheActorService->getFromAccount($account, true);
		} catch (Exception $e) {
			return;
		}

		// A mention is addressed the way Mastodon addresses one
		// (`Account#preferred_inbox_url`): the instance's shared inbox where it
		// publishes one, the personal inbox otherwise. Who the activity is for
		// is carried by `to`/`cc`, not by which inbox it was posted to, so
		// three people mentioned on one server are one delivery instead of
		// three — and a mention of somebody who also follows the author is the
		// same inbox the follower fan-out already uses, which is what lets the
		// queue recognise it as one.
		$inbox = $actor->getSharedInbox() !== '' ? $actor->getSharedInbox() : $actor->getInbox();

		$priority = InstancePath::PRIORITY_MEDIUM;
		if ($type === Stream::TYPE_DIRECT) {
			$priority = InstancePath::PRIORITY_HIGH;
			$stream->addToArray($actor->getId());
			$stream->setFilterDuplicate(true); // TODO: really needed ?
		} else {
			$stream->addCc($actor->getId());
		}

		$stream->addTag(
			[
				'type' => 'Mention',
				'href' => $actor->getId(),
				'name' => '@' . $account
			]
		);

		// the addressing and the tag stand whatever happens next: they are what
		// renders the mention here and what tells every recipient who it names
		if ($inbox === '') {
			$this->logger->notice(
				'cannot deliver a mention: the actor has neither a shared inbox nor an inbox',
				['actor' => $actor->getId(), 'account' => $account]
			);

			return;
		}

		$stream->addInstancePath(
			new InstancePath($inbox, InstancePath::TYPE_INBOX, $priority)
		);
	}

	/**
	 * The `Emoji` tags a post needs for the shortcodes written in it.
	 *
	 * The shortcode stays in the content as text; the tag beside it says where
	 * the picture is. That is how every fediverse server does it, and it is
	 * why an instance that has never heard of `:blobcat:` still renders the
	 * post — it dereferences the icon out of the tag rather than looking the
	 * name up anywhere. A shortcode this instance has no picture for is left
	 * as the text it already was, here and everywhere it is delivered.
	 *
	 * The spoiler text is scanned too: it is written by the same person in the
	 * same composer, and a content warning whose emoji did not render was the
	 * one place the shortcode showed through.
	 */
	public function addCustomEmojis(Stream $stream, string ...$texts): void {
		// rebuilt rather than appended to: an edit that takes a shortcode out
		// would otherwise leave the tag behind, and one that puts a new
		// shortcode in has to gain a tag or the emoji renders nowhere
		$stream->setTags(array_values(array_filter(
			$stream->getTags(),
			static fn (array $tag): bool => ($tag['type'] ?? '') !== 'Emoji'
		)));

		foreach ($this->emojiService->tagsFor(implode(' ', $texts)) as $tag) {
			$stream->addTag($tag);
		}
	}

	/**
	 * The href is the hashtag timeline this instance actually serves — the URL
	 * `UnifiedSearchProvider` hands out for a hashtag. It used to be
	 * `<social url>tag/<tag>`, a path no route answers, so following a tag on a
	 * post from here led every reader, local or remote, to a 404.
	 */
	public function addHashtag(Note $note, string $hashtag) {
		$note->addTag(
			[
				'type' => 'Hashtag',
				'href' => $this->urlGenerator->linkToRouteAbsolute(
					'social.Navigation.timeline', ['path' => 'tags/' . strtolower($hashtag)]
				),
				'name' => '#' . $hashtag
			]
		);
	}

	/**
	 * @param Stream $stream
	 * @param string $type
	 * @param array $accounts
	 */
	public function addRecipients(Stream $stream, string $type, array $accounts) {
		foreach ($accounts as $account) {
			$this->addRecipient($stream, $type, $account);
		}
	}

	/**
	 * @param Note $note
	 * @param array $hashtags
	 */
	public function addHashtags(Note $note, array $hashtags) {
		$note->setHashtags($hashtags);
		foreach ($hashtags as $hashtag) {
			$this->addHashtag($note, $hashtag);
		}
	}

	/**
	 * @param Note $note
	 * @param string $replyTo
	 *
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws StreamNotFoundException
	 * @throws UnauthorizedFediverseException
	 */
	public function replyTo(Note $note, string $replyTo) {
		if ($replyTo === '') {
			return;
		}

		$author = $this->getAuthorFromPostId($replyTo);
		$note->setInReplyTo($replyTo);

		// The author of the post being replied to is the one recipient that
		// matters most, and `endpoints.sharedInbox` is optional — without the
		// fallback, a reply to anyone on an instance that publishes only a
		// personal inbox was addressed to host '' and never arrived.
		$inbox = $author->getSharedInbox() !== '' ? $author->getSharedInbox() : $author->getInbox();
		if ($inbox === '') {
			$this->logger->notice(
				'cannot deliver a reply: the author has neither a shared inbox nor an inbox',
				['author' => $author->getId(), 'inReplyTo' => $replyTo]
			);

			return;
		}

		// TODO - type can be NOT public !
		$note->addInstancePath(
			new InstancePath($inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH)
		);
	}

	/**
	 * @param Stream $item
	 * @param string $type
	 *
	 * @throws Exception
	 */
	public function deleteLocalItem(Stream $item, string $type = '') {
		if (!$item->isLocal()) {
			return;
		}

		$item->setActorId($item->getAttributedTo());
		try {
			$actor = $this->cacheActorService->getFromId($item->getAttributedTo());
			$item->addInstancePath(
				new InstancePath(
					$actor->getId(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
				)
			);
		} catch (\Exception $e) {
		}
		$this->addressBoostersAndRepliers($item);
		$this->activityService->deleteActivity($item);
		$this->streamRequest->deleteById($item->getId(), $type);
	}

	/**
	 * A post travels further than the author's followers and the instances it
	 * was addressed to: every boost carried it to the booster's followers, and
	 * every reply to the replier's. Mastodon sends the Delete to the inboxes of
	 * everyone who boosted or replied as well; without that, the post lingers on
	 * every instance that only ever saw it through a boost. The shared inbox is
	 * used where the actor has one — a server with ten boosters is one delivery.
	 */
	private function addressBoostersAndRepliers(Stream $item): void {
		$inboxes = [];
		foreach ($this->streamRequest->getAnnouncesAndRepliesTo($item->getId()) as $interaction) {
			$actor = $interaction->getActor();
			if ($actor === null || $actor->isLocal()) {
				continue;
			}

			$inbox = $actor->getSharedInbox() !== '' ? $actor->getSharedInbox() : $actor->getInbox();
			if ($inbox === '' || isset($inboxes[$inbox])) {
				continue;
			}

			$inboxes[$inbox] = true;
			$item->addInstancePath(
				new InstancePath($inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM)
			);
		}
	}

	/**
	 * @param string $id
	 * @param bool $asViewer
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	public function getStreamById(
		string $id,
		bool $asViewer = false,
		int $format = ACore::FORMAT_ACTIVITYPUB,
	): Stream {
		return $this->streamRequest->getStreamById($id, $asViewer, $format);
	}

	/**
	 * @param int $nid
	 *
	 * @return array
	 */
	public function getContextByNid(int $nid): array {
		$curr = $post = $this->streamRequest->getStreamByNid($nid);

		$ancestors = [];
		for ($i = 0; $i < self::ANCESTOR_LIMIT; $i++) {
			if ($curr->getInReplyTo() === '') {
				break;
			}

			try {
				$curr = $this->streamRequest->getStreamById($curr->getInReplyTo(), true);
				$curr->setExportFormat(ACore::FORMAT_LOCAL);
				$ancestors[] = $curr;
			} catch (StreamNotFoundException $e) {
				break; // ancestor might be out of range for viewer
			}
		}

		return [
			'ancestors' => array_reverse($ancestors),
			'descendants' => $this->streamRequest->getDescendants($post->getId())
		];
	}

	/**
	 * @param string $id
	 * @param bool $asViewer
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	public function getStreamByNid(int $nid): Stream {
		return $this->streamRequest->getStreamByNid($nid);
	}

	public function updateStream(Stream $stream): void {
		$this->streamRequest->update($stream);
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
	public function getRepliesByParentId(
		string $id,
		int $since = 0,
		int $limit = 5,
		bool $asViewer = false,
	): array {
		return $this->streamRequest->getRepliesByParentId($id, $since, $limit, $asViewer);
	}

	/**
	 * @param int $since
	 * @param int $limit
	 * @param int $format
	 *
	 * @return Note[]
	 * @throws DateTimeException
	 * @deprecated
	 */
	public function getStreamHome(
		int $since = 0,
		int $limit = 5,
		int $format = Stream::FORMAT_ACTIVITYPUB,
	): array {
		return $this->streamRequest->getTimelineHome_dep($since, $limit, $format);
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Note[]
	 */
	public function getTimeline(ProbeOptions $options): array {
		$posts = $this->withoutRepeatsOfPostsAlreadyInThePage(
			$this->streamRequest->getTimeline($options), $options
		);
		if ($options->getFormat() === ACore::FORMAT_LOCAL) {
			// one query each for the whole page, and only for pages a client
			// reads -- neither is part of the wire object
			$this->linkPreviewService->attachCards($posts);
			$this->placeService->attachPlaces($posts);
		}

		return $posts;
	}

	/**
	 * A page shows a post once.
	 *
	 * A boost is a row of its own that points at the post it repeats, so
	 * following both an author and somebody who boosts them put the same post
	 * in the timeline twice: once on its own and once again under "X boosted",
	 * with the same text, the same picture and the same actions. The boost is
	 * the one that goes, because the post is already there in the place its own
	 * time gives it — which is what Mastodon does with a reblog of something
	 * already in the feed. Two boosts of the same post by different people
	 * collapse to the first as well.
	 *
	 * Notifications are left alone: a favourite and a boost of the same post
	 * are two different things that happened to you, and a page of them is not
	 * a page of posts.
	 *
	 * @param Stream[] $posts
	 *
	 * @return Stream[]
	 */
	private function withoutRepeatsOfPostsAlreadyInThePage(array $posts, ProbeOptions $options): array {
		if ($options->getProbe() === ProbeOptions::NOTIFICATIONS || count($posts) < 2) {
			return $posts;
		}

		$originals = [];
		foreach ($posts as $post) {
			if ($post->getType() !== Announce::TYPE && $post->getId() !== '') {
				$originals[$post->getId()] = true;
			}
		}

		$page = [];
		$repeated = [];
		foreach ($posts as $post) {
			if ($post->getType() !== Announce::TYPE) {
				$page[] = $post;
				continue;
			}

			$of = $post->getObjectId();
			if ($of !== '' && (isset($originals[$of]) || isset($repeated[$of]))) {
				continue;
			}
			if ($of !== '') {
				$repeated[$of] = true;
			}
			$page[] = $post;
		}

		return $page;
	}

	/**
	 * The link preview of a single post, for the paths that serve one status
	 * rather than a page.
	 */
	public function attachCard(Stream $post): Stream {
		$this->linkPreviewService->attachCard($post);

		return $post;
	}

	/**
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 * @throws Exception
	 * @deprecated
	 */
	public function getStreamNotifications(int $since = 0, int $limit = 5): array {
		return $this->streamRequest->getTimelineNotifications_dep($since, $limit);
	}

	/**
	 * @param string $actorId
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 * @throws Exception
	 * @deprecated
	 */
	public function getStreamAccount(string $actorId, int $since = 0, int $limit = 5): array {
		return $this->streamRequest->getTimelineAccount_dep($actorId, $since, $limit);
	}

	/**
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 * @throws Exception
	 * @deprecated
	 */
	public function getStreamDirect(int $since = 0, int $limit = 5): array {
		return $this->streamRequest->getTimelineDirect_dep($since, $limit);
	}

	/**
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 * @throws Exception
	 * @deprecated
	 */
	public function getStreamLocalTimeline(int $since = 0, int $limit = 5): array {
		return $this->streamRequest->getTimelineGlobal_dep($since, $limit, true);
	}

	/**
	 * @param string $hashtag
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 * @throws Exception
	 */
	public function getStreamLocalTag(string $hashtag, int $since = 0, int $limit = 5): array {
		return $this->streamRequest->getTimelineTag($hashtag, $since, $limit);
	}

	/**
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamInternalTimeline(int $since = 0, int $limit = 5): array {
		// TODO - admin should be able to provide a list of 'friendly/internal' instance of ActivityPub
		return [];
	}

	/**
	 *
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 * @throws Exception
	 */
	public function getStreamGlobalTimeline(int $since = 0, int $limit = 5): array {
		return $this->streamRequest->getTimelineGlobal_dep($since, $limit, false);
	}

	/**
	 *
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 * @throws Exception
	 */
	public function getStreamLiked(int $since = 0, int $limit = 5): array {
		return $this->streamRequest->getTimelineLiked($since, $limit);
	}

	/**
	 * @param $noteId
	 *
	 * @return Person
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws StreamNotFoundException
	 * @throws RedundancyLimitException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws RequestResultNotJsonException
	 * @throws UnauthorizedFediverseException
	 */
	public function getAuthorFromPostId(string $noteId) {
		$note = $this->streamRequest->getStreamById($noteId);

		return $this->cacheActorService->getFromId($note->getAttributedTo());
	}

	/**
	 * @param Person $actor
	 *
	 * @return OrderedCollection
	 */
	/**
	 * @param Person $actor
	 *
	 * @return OrderedCollection
	 */
	public function getOutboxCollection(Person $actor): OrderedCollection {
		// `last` used to be advertised as `?page=1&min_id=0`, which is not a
		// page the controller serves; `paged()` derives both links from the
		// page size the outbox actually pages at.
		return OrderedCollection::paged(
			$actor->getOutbox(),
			$this->getInt('post', $actor->getDetails('count')),
			$actor->getOutbox()
		);
	}

	/**
	 * The `replies` collection of a post: what a peer dereferences after
	 * reading `replies` on the note itself.
	 *
	 * A reply reaches the instances that hold the post it answers and nowhere
	 * else, so without this a reader on a third instance sees a post with no
	 * replies. Mastodon publishes one on every note and walks one on every note
	 * it fetches.
	 */
	public function getRepliesCollection(Stream $post): OrderedCollection {
		$id = $post->getId() . Stream::REPLIES_PATH;

		return OrderedCollection::paged(
			$id, $this->streamRequest->countPublicRepliesTo($post->getId()), $id
		);
	}

	/**
	 * One page of it: the ids of the public replies, oldest first.
	 *
	 * Ids and not the replies themselves. A reply is its author's document,
	 * held and served by their instance, and handing out a copy of it from here
	 * would publish this instance's idea of a post somebody else may since have
	 * edited or deleted. It is also what keeps the page small: a thread of
	 * forty replies is forty URIs.
	 */
	public function getRepliesPage(Stream $post, int $page): OrderedCollectionPage {
		$id = $post->getId() . Stream::REPLIES_PATH;

		$replies = $this->streamRequest->getPublicRepliesTo(
			$post->getId(),
			OrderedCollection::PAGE_SIZE,
			($page - 1) * OrderedCollection::PAGE_SIZE
		);

		return OrderedCollectionPage::of(
			$id, $id, $page, array_values(array_map(
				static fn (Stream $reply): string => $reply->getId(), $replies
			))
		);
	}

	/**
	 * @param Person $actor
	 */
	public function syncRemoteTimeline(Person $actor): int {
		if ($actor->isLocal()) {
			return 0;
		}

		$synced = 0;
		try {
			$outboxUrl = $actor->getOutbox();
			if (empty($outboxUrl)) {
				$this->logger->info('[syncRemoteTimeline] No outbox URL for actor', ['actor' => $actor->getId()]);
				return 0;
			}

			$this->logger->debug('[syncRemoteTimeline] Fetching outbox', ['url' => $outboxUrl, 'actor' => $actor->getId()]);
			$outboxData = $this->curlService->retrieveObject($outboxUrl);

			// Follow 'first' to get the first page
			$pageData = $outboxData;
			if (isset($outboxData['first'])) {
				if (is_array($outboxData['first'])) {
					$pageData = $outboxData['first'];
				} elseif (is_string($outboxData['first'])) {
					$this->logger->debug('[syncRemoteTimeline] Following first page', ['url' => $outboxData['first']]);
					$pageData = $this->curlService->retrieveObject($outboxData['first']);
				}
			}

			$items = $pageData['orderedItems'] ?? $pageData['items'] ?? [];
			if (!is_array($items)) {
				$this->logger->debug('[syncRemoteTimeline] No items in page', ['actor' => $actor->getId()]);
				return 0;
			}

			$this->logger->info('[syncRemoteTimeline] Processing items', ['actor' => $actor->getId(), 'count' => count($items)]);

			foreach (array_slice($items, 0, self::SYNC_ITEM_LIMIT) as $itemData) {
				try {
					// An OrderedCollection may list bare ids as well as objects,
					// and everything below reads the entry as an array.
					if (!is_array($itemData)) {
						continue;
					}

					// Extract the Note data from the activity item
					$noteData = null;
					$itemType = $this->get('type', $itemData, '');
					if ($itemType === 'Create' && isset($itemData['object']) && is_array($itemData['object'])) {
						$noteData = $itemData['object'];
					} elseif ($itemType === 'Note') {
						$noteData = $itemData;
					} else {
						continue;
					}

					if ($noteData === null) {
						continue;
					}

					$noteId = $this->get('id', $noteData, '');
					if ($noteId === '') {
						continue;
					}

					// This path stores what a remote server said about itself, so it may
					// only yield notes that live on that server and belong to the actor
					// whose outbox is being read. Anything else is that server speaking
					// for someone it does not host.
					$attributedTo = $this->get('attributedTo', $noteData, $actor->getId());
					$actorHost = strtolower((string)parse_url($actor->getId(), PHP_URL_HOST));
					$noteHost = strtolower((string)parse_url($noteId, PHP_URL_HOST));
					$authorHost = strtolower((string)parse_url($attributedTo, PHP_URL_HOST));
					if ($actorHost === '' || $noteHost !== $actorHost || $authorHost !== $actorHost) {
						$this->logger->debug(
							'[syncRemoteTimeline] Skipping foreign item',
							['actor' => $actor->getId(), 'note' => $noteId]
						);
						continue;
					}

					// Check if we already have it
					try {
						$this->streamRequest->getStreamById($noteId);
						continue;
					} catch (StreamNotFoundException $e) {
					}

					// Manually create a Note with only the fields we need (no attachment
					// processing). Storing without NoteInterface::save() also means
					// storing without the validation ACore::import() does on the inbox
					// path, so every field a remote server controls is put through the
					// same helpers here.
					$note = new Note();
					$note->setId($noteId);
					$note->setType('Note');
					$note->setUrl($note->validate(ACore::AS_URL, 'url', $noteData, ''));
					$note->setAttributedTo($attributedTo);
					$note->setPublished($this->get('published', $noteData, date('c')));
					$note->setContent($note->validate(ACore::AS_CONTENT, 'content', $noteData, ''));
					$note->setSummary($note->validate(ACore::AS_STRING, 'summary', $noteData, ''));
					$note->setSensitive(!empty($noteData['sensitive']));
					$note->setSource(json_encode($noteData, JSON_UNESCAPED_SLASHES));
					$note->setLocal(false);

					// Set conversation / context
					$conversation = $note->validate(ACore::AS_ID, 'conversation', $noteData, '');
					$context = $note->validate(ACore::AS_ID, 'context', $noteData, '');
					if ($context !== '') {
						$conversation = $context;
					}
					if ($conversation !== '') {
						$note->setConversation($conversation);
					}
					$note->setInReplyTo($note->validate(ACore::AS_ID, 'inReplyTo', $noteData, ''));

					// Set to/cc arrays
					$note->setToArray($this->recipientList($noteData['to'] ?? []));
					$note->setCcArray($this->recipientList($noteData['cc'] ?? []));

					// Process tags (hashtags, mentions) without triggering downloads.
					// A hashtag is the one stored string with no read-time filter behind
					// it — it reaches the composer's autocomplete as it was stored — so
					// the tag names are sanitised here, exactly as `ACore::import()` and
					// `Note::fillHashtags()` do for anything arriving over the inbox.
					$note->setTags($note->validateArray(ACore::AS_TAGS, 'tag', $noteData, []));
					$note->fillHashtags();

					// Attachments are not processed during sync to avoid
					// memory-exhausting remote file downloads. They will be
					// fetched lazily from the source JSON when needed.

					// Convert published time
					try {
						$note->convertPublished();
					} catch (Exception $e) {
					}

					// Extract likes/shares/replies counts from ActivityPub collections
					if (isset($noteData['likes']['totalItems'])) {
						$remoteLikes = (int)$noteData['likes']['totalItems'];
						$note->setDetailInt('likes', $remoteLikes);
						$note->setDetailInt('remote_likes', $remoteLikes);
					}
					if (isset($noteData['shares']['totalItems'])) {
						$remoteBoosts = (int)$noteData['shares']['totalItems'];
						$note->setDetailInt('boosts', $remoteBoosts);
						$note->setDetailInt('remote_boosts', $remoteBoosts);
					}
					if (isset($noteData['replies']['totalItems'])) {
						$note->setDetailInt('replies', (int)$noteData['replies']['totalItems']);
					}

					// Save the Note directly without going through NoteInterface
					// (which would trigger attachment downloads)
					$this->streamRequest->save($note);
					$synced++;
					$this->logger->debug('[syncRemoteTimeline] Saved post', ['id' => $note->getId()]);
				} catch (Exception $e) {
					$this->logger->warning('[syncRemoteTimeline] Failed to process item', [
						'error' => $e->getMessage(),
					]);
				}
			}
		} catch (Exception $e) {
			$this->logger->warning('[syncRemoteTimeline] Failed to fetch outbox', [
				'actor' => $actor->getId(),
				'error' => $e->getMessage()
			]);
		}

		if ($synced > 0) {
			$this->logger->info('[syncRemoteTimeline] Sync complete', ['actor' => $actor->getId(), 'synced' => $synced]);
		}

		return $synced;
	}

	/**
	 * The `to`/`cc` of a remote object: a single recipient may be sent as a bare
	 * string instead of a list, and anything in the list that is not a string is
	 * not an address this app can store.
	 */
	private function recipientList(mixed $value): array {
		return array_values(array_filter(is_array($value) ? $value : [$value], 'is_string'));
	}
}
