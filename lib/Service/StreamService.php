<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
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
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\OrderedCollection;
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

	private IUrlGenerator $urlGenerator;
	private StreamRequest $streamRequest;
	private ActivityService $activityService;
	private CacheActorService $cacheActorService;
	private ConfigService $configService;
	private CurlService $curlService;
	private LoggerInterface $logger;

	private const ANCESTOR_LIMIT = 5;

	public function __construct(
		IUrlGenerator $urlGenerator,
		StreamRequest $streamRequest,
		ActivityService $activityService,
		CacheActorService $cacheActorService,
		ConfigService $configService,
		CurlService $curlService,
		LoggerInterface $logger,
	) {
		$this->urlGenerator = $urlGenerator;
		$this->streamRequest = $streamRequest;
		$this->activityService = $activityService;
		$this->cacheActorService = $cacheActorService;
		$this->configService = $configService;
		$this->curlService = $curlService;
		$this->logger = $logger;
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
						$actor->getFollowers(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				$stream->addCc(ACore::CONTEXT_PUBLIC);
				break;

			case Stream::TYPE_FOLLOWERS:
				$stream->setTo($actor->getFollowers());
				$stream->addInstancePath(
					new InstancePath(
						$actor->getFollowers(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				break;

			case Stream::TYPE_ANNOUNCE:
				$stream->addInstancePath(
					new InstancePath(
						$actor->getFollowers(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				$stream->addCc($actor->getFollowers());
				break;

			case Stream::TYPE_DIRECT:
				break;

			default:
				$stream->setTo(ACore::CONTEXT_PUBLIC);
				$stream->addCc($actor->getFollowers());
				$stream->addInstancePath(
					new InstancePath(
						$actor->getFollowers(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				break;
		}
	}


	/**
	 * @param $stream
	 */
	public function detectType(Stream $stream) {
		if (in_array(ACore::CONTEXT_PUBLIC, $stream->getToAll())) {
			$stream->setTimeline(Stream::TYPE_PUBLIC);

			return;
		}

		if (in_array(ACore::CONTEXT_PUBLIC, $stream->getCcArray())) {
			$stream->setType(Stream::TYPE_UNLISTED);

			return;
		}

		try {
			$actor = $this->cacheActorService->getFromId($stream->getAttributedTo());
			echo json_encode($actor) . "\n";
		} catch (Exception $e) {
			return;
		}
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

		$instancePath = new InstancePath(
			$actor->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM
		);
		if ($type === Stream::TYPE_DIRECT) {
			$instancePath->setPriority(InstancePath::PRIORITY_HIGH);
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

		$stream->addInstancePath($instancePath);
	}


	/**
	 * @param Note $note
	 * @param string $hashtag
	 */
	public function addHashtag(Note $note, string $hashtag) {
		try {
			$note->addTag(
				[
					'type' => 'Hashtag',
					'href' => $this->configService->getSocialUrl() . 'tag/' . strtolower($hashtag),
					'name' => '#' . $hashtag
				]
			);
		} catch (SocialAppConfigException $e) {
		}
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
		// TODO - type can be NOT public !
		$note->addInstancePath(
			new InstancePath(
				$author->getSharedInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH
			)
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
		$this->activityService->deleteActivity($item);
		$this->streamRequest->deleteById($item->getId(), $type);
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
		return $this->streamRequest->getTimeline($options);
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
		$collection = new OrderedCollection();
		$collection->setId($actor->getOutbox());
		$collection->setTotalItems($this->getInt('post', $actor->getDetails('count')));

		$link = $this->urlGenerator->linkToRouteAbsolute(
			'social.ActivityPub.outbox',
			['username' => $actor->getPreferredUsername()]
		);

		$collection->setFirst($link . '?page=1');
		$collection->setLast($link . '?page=1&min_id=0');

		return $collection;
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

			foreach ($items as $itemData) {
				try {
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

					$noteId = $noteData['id'] ?? '';
					if ($noteId === '') {
						continue;
					}

					// Check if we already have it
					try {
						$this->streamRequest->getStreamById($noteId);
						continue;
					} catch (StreamNotFoundException $e) {
					}

					// Manually create a Note with only the fields we need (no attachment processing)
					$note = new Note();
					$note->setId($noteId);
					$note->setType('Note');
					$note->setUrl($noteData['url'] ?? '');
					$note->setAttributedTo($noteData['attributedTo'] ?? $actor->getId());
					$note->setPublished($noteData['published'] ?? date('c'));
					$note->setContent($noteData['content'] ?? '');
					$note->setSummary($noteData['summary'] ?? '');
					$note->setSensitive(!empty($noteData['sensitive']));
					$note->setSource(json_encode($noteData, JSON_UNESCAPED_SLASHES));
					$note->setLocal(false);

					// Set conversation / context
					if (!empty($noteData['conversation'])) {
						$note->setConversation($noteData['conversation']);
					}
					if (!empty($noteData['context'])) {
						$note->setConversation($noteData['context']);
					}
					if (!empty($noteData['inReplyTo'])) {
						$note->setInReplyTo($noteData['inReplyTo']);
					}

					// Set to/cc arrays
					$to = $noteData['to'] ?? [];
					$cc = $noteData['cc'] ?? [];
					$note->setToArray(is_array($to) ? $to : [$to]);
					$note->setCcArray(is_array($cc) ? $cc : [$cc]);

					// Process tags (hashtags, mentions) without triggering downloads
					$tagData = $noteData['tag'] ?? [];
					if (is_array($tagData)) {
						$hashtags = [];
						$allTags = [];
						foreach ($tagData as $tag) {
							if (is_array($tag)) {
								$tagType = $tag['type'] ?? '';
								if ($tagType === 'Hashtag') {
									$hashtags[] = ltrim($tag['name'] ?? '', '#');
								}
								$allTags[] = $tag;
							}
						}
						$note->setHashtags($hashtags);
						if (!empty($allTags)) {
							$note->setTags($allTags);
						}
					}

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
}
