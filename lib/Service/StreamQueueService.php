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

namespace OCA\Social\Service;


use daita\MySmallPhpTools\Exceptions\MalformedArrayException;
use daita\MySmallPhpTools\Model\Cache;
use daita\MySmallPhpTools\Model\CacheItem;
use OCA\Social\AP;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Db\StreamQueueRequest;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Exceptions\RedundancyLimitException;
use daita\MySmallPhpTools\Exceptions\RequestContentException;
use daita\MySmallPhpTools\Exceptions\RequestNetworkException;
use daita\MySmallPhpTools\Exceptions\RequestResultNotJsonException;
use daita\MySmallPhpTools\Exceptions\RequestResultSizeException;
use daita\MySmallPhpTools\Exceptions\RequestServerException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamQueue;


/**
 * Class StreamQueueService
 *
 * @package OCA\Social\Service
 */
class StreamQueueService {


	/** @var StreamRequest */
	private $streamRequest;

	/** @var StreamQueueRequest */
	private $streamQueueRequest;

	/** @var ImportService */
	private $importService;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var CurlService */
	private $curlService;

	/** @var MiscService */
	private $miscService;


	/**
	 * StreamQueueService constructor.
	 *
	 * @param StreamRequest $streamRequest
	 * @param StreamQueueRequest $streamQueueRequest
	 * @param CacheActorService $cacheActorService
	 * @param ImportService $importService
	 * @param CurlService $curlService
	 * @param MiscService $miscService
	 */
	public function __construct(
		StreamRequest $streamRequest, StreamQueueRequest $streamQueueRequest,
		CacheActorService $cacheActorService, ImportService $importService,
		CurlService $curlService, MiscService $miscService
	) {
		$this->streamRequest = $streamRequest;
		$this->streamQueueRequest = $streamQueueRequest;
		$this->importService = $importService;
		$this->cacheActorService = $cacheActorService;
		$this->curlService = $curlService;
		$this->miscService = $miscService;
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
			case 'Cache':
				$this->manageStreamQueueCache($queue);
				break;

			default:
				$this->deleteCache($queue);
				break;
		}
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
				$this->cacheItem($item);
				$item->setStatus(StreamQueue::STATUS_SUCCESS);
				$cache->updateItem($item);
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
	private function cacheItem(CacheItem &$item) {
		try {
			$note = $this->streamRequest->getStreamById($item->getUrl());
		} catch (StreamNotFoundException $e) {
			$data = $this->curlService->retrieveObject($item->getUrl());
			$object = AP::$activityPub->getItemFromData($data);

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

			$interface = AP::$activityPub->getInterfaceForItem($object);
			$interface->save($object);

			$note = $this->streamRequest->getStreamById($object->getId());
		}

		$item->setContent(json_encode($note, JSON_UNESCAPED_SLASHES));
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
			$interface = AP::$activityPub->getInterfaceForItem($stream);
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

