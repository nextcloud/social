<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AP;
use OCA\Social\Db\StreamQueueRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamQueue;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Model\Cache;
use OCA\Social\Tools\Model\CacheItem;

/**
 * Class StreamQueueService
 *
 * @package OCA\Social\Service
 */
class StreamQueueService {
	/**
	 * Detail stamped on every ancestor fetched for a reply: how many levels up
	 * the climb already is. NoteInterface::save() stops queueing the next parent
	 * once it reaches MAX_ANCESTOR_DEPTH, so a thread of a thousand messages
	 * costs eight fetches, not a thousand. Mastodon bounds the same climb.
	 */
	public const DETAIL_ANCESTOR_DEPTH = 'ancestor_depth';
	public const MAX_ANCESTOR_DEPTH = 8;

	public function __construct(
		private StreamRequest $streamRequest,
		private StreamQueueRequest $streamQueueRequest,
		private CacheActorService $cacheActorService,
		private ImportService $importService,
		private CurlService $curlService,
		private MiscService $miscService,
		private LinkPreviewService $linkPreviewService,
	) {
	}

	/**
	 * @param string $token
	 * @param string $type
	 * @param string $streamId
	 */
	public function generateStreamQueue(string $token, string $type, string $streamId) {
		$cache = new StreamQueue($token, $type, $streamId);

		$this->streamQueueRequest->create($cache);
	}

	/**
	 * @param int $total
	 *
	 * @return StreamQueue[]
	 */
	public function getRequestStandby(int &$total = 0): array {
		$queue = $this->streamQueueRequest->getStandby();
		$total = sizeof($queue);

		$result = [];
		foreach ($queue as $request) {
			$delay = floor(pow($request->getTries(), 4) / 3);
			if ($request->getLast() < (time() - $delay)) {
				$result[] = $request;
			}
		}

		return $result;
	}

	/**
	 * @param string $token
	 */
	public function cacheStreamByToken(string $token) {
		$items = $this->streamQueueRequest->getFromToken($token);

		foreach ($items as $item) {
			$this->manageStreamQueue($item);
		}
	}

	/**
	 * @param StreamQueue $queue
	 */
	public function manageStreamQueue(StreamQueue $queue) {
		try {
			$this->initCache($queue);
		} catch (QueueStatusException $e) {
			return;
		}

		switch ($queue->getType()) {
			case StreamQueue::TYPE_CACHE:
				$this->manageStreamQueueCache($queue);
				break;

			case StreamQueue::TYPE_LINK_PREVIEW:
				$this->manageStreamQueueLinkPreview($queue);
				break;

			default:
				$this->deleteCache($queue);
				break;
		}
	}

	/**
	 * Reads the page a post links to, once. A post without a link, or a page
	 * that cannot be read, ends the queue item for good: retrying would hit
	 * the same wall on every run.
	 */
	private function manageStreamQueueLinkPreview(StreamQueue $queue): void {
		try {
			$stream = $this->streamRequest->getStreamById($queue->getStreamId());
		} catch (StreamNotFoundException $e) {
			$this->deleteCache($queue);

			return;
		}

		$this->linkPreviewService->generate($stream);
		$this->deleteCache($queue);
	}

	/**
	 * @param StreamQueue $queue
	 */
	private function manageStreamQueueCache(StreamQueue $queue) {
		try {
			$stream = $this->streamRequest->getStreamById($queue->getStreamId());
		} catch (StreamNotFoundException $e) {
			$this->deleteCache($queue);

			return;
		}

		if (!$stream->hasCache()) {
			$this->deleteCache($queue);

			return;
		}

		try {
			if ($this->manageStreamCache($stream)) {
				$this->endCache($queue, true);
			} else {
				$this->endCache($queue, false);
			}
		} catch (SocialAppConfigException $e) {
		}
	}

	/**
	 * @param Stream $stream
	 *
	 * @return bool
	 * @throws SocialAppConfigException
	 */
	private function manageStreamCache(Stream $stream): bool {
		$cache = $stream->getCache();

		foreach ($cache->getItems() as $item) {
			// TODO: PHP7.2 (NC16) : multiple exception per catch

			try {
				$this->cacheItem($stream, $item);
				if ($stream->getType() === Note::TYPE) {
					// a reply only needed its parent fetched; the parent is a row
					// of its own now and nothing reads a copy out of the reply
					$cache->removeItem($item->getUrl());
				} else {
					$item->setStatus(StreamQueue::STATUS_SUCCESS);
					$cache->updateItem($item);
				}
			} catch (StreamNotFoundException $e) {
				$this->miscService->log(
					'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
					. $e->getMessage(), 1
				);
				$cache->removeItem($item->getUrl());
			} catch (InvalidOriginException $e) {
				$this->miscService->log(
					'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
					. $e->getMessage(), 1
				);
				$cache->removeItem($item->getUrl());
			} catch (RequestContentException $e) {
				$this->miscService->log(
					'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
					. $e->getMessage(), 1
				);
				$cache->removeItem($item->getUrl());
			} catch (MalformedArrayException $e) {
				$this->miscService->log(
					'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
					. $e->getMessage(), 1
				);
				$cache->removeItem($item->getUrl());
			} catch (RedundancyLimitException $e) {
				$this->miscService->log(
					'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
					. $e->getMessage(), 1
				);
				$cache->removeItem($item->getUrl());
			} catch (InvalidResourceException $e) {
				$this->miscService->log(
					'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
					. $e->getMessage(), 1
				);
				$cache->removeItem($item->getUrl());
			} catch (RequestResultSizeException $e) {
				$this->miscService->log(
					'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
					. $e->getMessage(), 1
				);
				$cache->removeItem($item->getUrl());
			} catch (ItemUnknownException $e) {
				$this->miscService->log(
					'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
					. $e->getMessage(), 1
				);
				$cache->removeItem($item->getUrl());
			} catch (UnauthorizedFediverseException $e) {
				$this->miscService->log(
					'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
					. $e->getMessage(), 1
				);
				$cache->removeItem($item->getUrl());
			} catch (RequestNetworkException $e) {
				$this->miscService->log(
					'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
					. $e->getMessage(), 1
				);
				$item->incrementError();
			} catch (RequestResultNotJsonException $e) {
				$this->miscService->log(
					'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
					. $e->getMessage(), 1
				);
				$item->incrementError();
			} catch (RequestServerException $e) {
				$this->miscService->log(
					'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
					. $e->getMessage(), 1
				);
				$item->incrementError();
			}
		}

		return $this->updateCache($stream, $cache);
	}

	/**
	 * Fetches the object a cache item names, unless it is stored already: the
	 * object of a boost, or the parent of a reply. Either way it is checked like
	 * anything fetched — id equal to the URL asked for, origin the URL's host,
	 * author on that host (NoteInterface::save()) — and saved through the same
	 * interface an inbox delivery goes through.
	 *
	 * @param Stream $stream the stream whose cache wants the item
	 * @param CacheItem $item
	 *
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws StreamNotFoundException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	private function cacheItem(Stream $stream, CacheItem &$item) {
		try {
			$note = $this->streamRequest->getStreamById($item->getUrl());
		} catch (StreamNotFoundException $e) {
			$data = $this->curlService->retrieveObject($item->getUrl());
			$object = AP::instance()->getItemFromData($data);

			$origin = parse_url($item->getUrl(), PHP_URL_HOST);
			$object->setOrigin($origin, SignatureService::ORIGIN_REQUEST, time());

			if ($object->getId() !== $item->getUrl()) {
				throw new InvalidOriginException(
					'StreamQueueService::cacheItem - objectId: ' . $object->getId() . ' - itemUrl: '
					. $item->getUrl()
				);
			}

			if ($object->getType() !== Note::TYPE) {
				throw new InvalidResourceException();
			}

			/** @var Stream $object */
			$this->cacheActorService->getFromId($object->getAttributedTo());

			// one level further up than the stream that asked for it; the save
			// below queues the next parent only while this stays under the cap
			$object->setDetailInt(
				self::DETAIL_ANCESTOR_DEPTH,
				$stream->getDetailInt(self::DETAIL_ANCESTOR_DEPTH) + 1
			);

			$interface = AP::instance()->getInterfaceForItem($object);
			$interface->save($object);

			$note = $this->streamRequest->getStreamById($object->getId());
			$this->countStoredReplies($note);
		}

		$item->setContent(json_encode($note, JSON_UNESCAPED_SLASHES));
	}

	/**
	 * The replies that arrived before their parent did were stored without
	 * bumping anything — there was no parent to bump. Counted now, the way
	 * NoteInterface::updateDetails() counts them when the parent came first.
	 */
	private function countStoredReplies(Stream $note): void {
		$stored = $this->streamRequest->countRepliesTo($note->getId());
		if ($stored === 0) {
			return;
		}

		$note->setDetailInt('replies', $note->getDetailInt('remote_replies') + $stored);
		$this->streamRequest->updateDetails($note);
	}

	/**
	 * @param Stream $stream
	 * @param Cache $cache
	 *
	 * @return bool
	 */
	private function updateCache(Stream $stream, Cache $cache): bool {
		$this->streamRequest->updateCache($stream, $cache);
		try {
			$interface = AP::instance()->getInterfaceForItem($stream);
			$interface->event($stream, 'updateCache');
		} catch (ItemUnknownException $e) {
		}

		$done = true;
		foreach ($cache->getItems() as $item) {
			if ($item->getStatus() !== StreamQueue::STATUS_SUCCESS) {
				$done = false;
			}
		}

		return $done;
	}

	/**
	 * @param StreamQueue $queue
	 *
	 * @throws QueueStatusException
	 */
	private function initCache(StreamQueue $queue) {
		$this->streamQueueRequest->setAsRunning($queue);
	}

	/**
	 * @param StreamQueue $queue
	 * @param bool $success
	 */
	private function endCache(StreamQueue $queue, bool $success) {
		try {
			if ($success === true) {
				$this->streamQueueRequest->setAsSuccess($queue);
			} else {
				$this->streamQueueRequest->setAsFailure($queue);
			}
		} catch (QueueStatusException $e) {
		}
	}

	/**
	 * @param StreamQueue $queue
	 */
	private function deleteCache(StreamQueue $queue) {
		$this->streamQueueRequest->delete($queue);
	}
}
