<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\EmptyQueueException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\NoHighPriorityRequestException;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Tombstone;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Model\NCRequest;
use OCA\Social\Tools\Model\Request;
use OCA\Social\Tools\Traits\TArrayTools;
use Psr\Log\LoggerInterface;

/**
 * Class ActivityService
 *
 * @package OCA\Social\Service
 */
class ActivityService {
	use TArrayTools;

	public const TIMEOUT_LIVE = 3;
	public const TIMEOUT_ASYNC = 10;
	public const TIMEOUT_SERVICE = 30;

	private StreamRequest $streamRequest;
	private FollowsRequest $followsRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private SignatureService $signatureService;
	private RequestQueueService $requestQueueService;
	private ConfigService $configService;
	private CurlService $curlService;
	private LoggerInterface $logger;

	private ?array $failInstances = null;

	public function __construct(
		StreamRequest $streamRequest,
		FollowsRequest $followsRequest,
		CacheActorsRequest $cacheActorsRequest,
		SignatureService $signatureService,
		RequestQueueService $requestQueueService,
		CurlService $curlService,
		ConfigService $configService,
		LoggerInterface $logger,
	) {
		$this->streamRequest = $streamRequest;
		$this->followsRequest = $followsRequest;
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->requestQueueService = $requestQueueService;
		$this->signatureService = $signatureService;
		$this->curlService = $curlService;
		$this->configService = $configService;
		$this->logger = $logger;
	}


	/**
	 * @param Person $actor
	 * @param ACore $item
	 * @param ACore $activity
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function createActivity(Person $actor, ACore $item, ?ACore &$activity = null): string {
		$activity = new Create();
		$item->setParent($activity);

		//		$this->activityStreamsService->initCore($activity);

		$activity->setObject($item);
		$activity->setId($item->getId() . '/activity');
		$activity->setInstancePaths($item->getInstancePaths());

		//		if ($item->getToArray() !== []) {
		//			$activity->setToArray($item->getToArray());
		//		} else {
		//			$activity->setTo($item->getTo());
		//		}

		$activity->setActor($actor);
		$this->signatureService->signObject($actor, $activity);

		// TODO: utiliser AP::$activityPub->getInterfaceFromType(Activity::TYPE)->save($item);

		$this->saveActivity($activity);

		return $this->request($activity);
	}


	/**
	 * @param ACore $item
	 *
	 * @return string
	 * @throws Exception
	 */
	public function deleteActivity(ACore $item): string {
		$delete = new Delete();
		$delete->setId($item->getId() . '#delete');
		$delete->setActorId($item->getActorId());

		$tombstone = new Tombstone($delete);
		$tombstone->setId($item->getId());

		$delete->setObject($tombstone);
		$delete->addInstancePaths($item->getInstancePaths());

		return $this->request($delete);
	}


	/**
	 * @param string $id
	 *
	 * @return ACore
	 * @throws InvalidResourceException
	 */
	public function getItem(string $id): ACore {
		if ($id === '') {
			throw new InvalidResourceException();
		}

		$requests = [
			'Note'
		];

		foreach ($requests as $request) {
			try {
				$interface = AP::$activityPub->getInterfaceFromType($request);

				return $interface->getItemById($id);
			} catch (Exception $e) {
			}
		}

		throw new InvalidResourceException();
	}


	/**
	 * @throws SocialAppConfigException
	 */
	public function request(ACore $activity): string {
		$author = $this->getAuthorFromItem($activity);
		$instancePaths = $this->generateInstancePaths($activity);
		$token = $this->requestQueueService->generateRequestQueue($instancePaths, $activity, $author);

		if ($token === '') {
			return '<request token not needed>';
		}

		$this->manageInit();

		try {
			$directRequest = $this->requestQueueService->getPriorityRequest($token);
			$directRequest->setTimeout(self::TIMEOUT_LIVE);
			$this->manageRequest($directRequest);
		} catch (NoHighPriorityRequestException $e) {
		} catch (EmptyQueueException $e) {
			return $token;
		}

		$requests = $this->requestQueueService->getRequestFromToken($token, RequestQueue::STATUS_STANDBY);
		if (sizeof($requests) > 0) {
			$this->curlService->asyncWithToken($token);
		}

		return $token;
	}


	public function manageInit() {
		$this->failInstances = [];
	}


	/**
	 * @param RequestQueue $queue
	 *
	 * @throws SocialAppConfigException
	 */
	public function manageRequest(RequestQueue $queue) {
		$host = $queue->getInstance()
			->getAddress();
		if (in_array($host, $this->failInstances)) {
			return;
		}

		try {
			$this->requestQueueService->initRequest($queue);
		} catch (QueueStatusException $e) {
			$this->logger->error('Error while trying to init request', [
				'exception' => $e,
			]);

			return;
		}

		$request = $this->generateRequestFromQueue($queue);

		try {
			$this->signatureService->signRequest($request, $queue);
			$this->curlService->retrieveJson($request);
			$this->requestQueueService->endRequest($queue, true);
		} catch (UnauthorizedFediverseException|RequestResultNotJsonException $e) {
			$this->requestQueueService->endRequest($queue, true);
		} catch (ActorDoesNotExistException|RequestContentException|RequestResultSizeException $e) {
			$this->logger->notice(
				'Error while managing request: ' . json_encode($request) . ' ' . get_class($e) . ': '
				. $e->getMessage()
			);
			$this->requestQueueService->deleteRequest($queue);
		} catch (RequestNetworkException|RequestServerException $e) {
			$this->logger->notice(
				'Temporary error while managing request: RequestServerException - ' . json_encode($request)
				. ' - ' . get_class($e) . ': ' . $e->getMessage()
			);
			$this->requestQueueService->endRequest($queue, false);
			$this->failInstances[] = $host;
		}
	}


	/** // ====> instanceService
	 *
	 * @param ACore $activity
	 *
	 * @return InstancePath[]
	 */
	private function generateInstancePaths(ACore $activity): array {
		$instancePaths = [];
		foreach ($activity->getInstancePaths() as $instancePath) {
			switch ($instancePath->getType()) {
				case InstancePath::TYPE_FOLLOWERS:
					$instancePaths =
						array_merge($instancePaths, $this->generateInstancePathsFollowers($instancePath));
					break;

				case InstancePath::TYPE_ALL:
					$instancePaths = array_merge($instancePaths, $this->generateInstancePathsAll());
					break;

				default:
					$instancePaths[] = $instancePath;
					break;
			}
		}

		return $instancePaths;
	}


	/**
	 * @param InstancePath $instancePath
	 *
	 * @return InstancePath[]
	 */
	private function generateInstancePathsFollowers(InstancePath $instancePath): array {
		$follows = $this->followsRequest->getByFollowId($instancePath->getUri());

		$sharedInboxes = [];
		$instancePaths = [];
		foreach ($follows as $follow) {
			if (!$follow->hasActor()) {
				// TODO - check if cache can be empty at this point ?
				continue;
			}

			$sharedInbox = $follow->getActor()
				->getSharedInbox();
			if (in_array($sharedInbox, $sharedInboxes)) {
				continue;
			}

			$sharedInboxes[] = $sharedInbox;
			$instancePaths[] = new InstancePath(
				$sharedInbox, InstancePath::TYPE_GLOBAL, $instancePath->getPriority()
			);
		}

		return $instancePaths;
	}


	/**
	 * @return InstancePath[]
	 */
	private function generateInstancePathsAll(): array {
		$sharedInboxes = $this->cacheActorsRequest->getSharedInboxes();
		$instancePaths = [];
		foreach ($sharedInboxes as $sharedInbox) {
			$instancePaths[] = new InstancePath(
				$sharedInbox,
				InstancePath::TYPE_GLOBAL,
				InstancePath::PRIORITY_LOW
			);
		}

		return $instancePaths;
	}


	private function generateRequestFromQueue(RequestQueue $queue): NCRequest {
		$path = $queue->getInstance();

		$requestType = Request::TYPE_GET;
		if ($path->getType() === InstancePath::TYPE_INBOX
			|| $path->getType() === InstancePath::TYPE_GLOBAL
			|| $path->getType() === InstancePath::TYPE_FOLLOWERS) {
			$requestType = Request::TYPE_POST;
		}

		$request = new NCRequest($path->getPath(), $requestType);
		$request->setTimeout($queue->getTimeout());
		$request->setDataJson($queue->getActivity());
		$request->setHost($path->getAddress());
		$request->setProtocol($path->getProtocol());

		return $request;
	}


	/**
	 * $signature = new LinkedDataSignature();
	 *
	 * @param ACore $activity
	 *
	 * @return string
	 */
	private function getAuthorFromItem(Acore $activity): string {
		if ($activity->hasActor()) {
			return $activity->getActor()
				->getId();
		}

		return $activity->getActorId();
	}


	/**
	 * @param ACore $activity
	 */
	private function saveActivity(ACore $activity) {
		// TODO: save activity in DB ?

		if ($activity->hasObject()) {
			$this->saveObject($activity->getObject());
		}
	}


	/**
	 * @param ACore $item
	 */
	private function saveObject(ACore $item) {
		try {
			if ($item->hasObject()) {
				$this->saveObject($item->getObject());
			}

			$service = AP::$activityPub->getInterfaceForItem($item);
			$service->save($item);
		} catch (ItemUnknownException $e) {
		} catch (ItemAlreadyExistsException $e) {
		}
	}
}
