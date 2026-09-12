<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
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
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Tombstone;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\AppFramework\Http;
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

	private ?array $failInstances = null;

	public function __construct(
		private StreamRequest $streamRequest,
		private FollowsRequest $followsRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private SignatureService $signatureService,
		private RequestQueueService $requestQueueService,
		private CurlService $curlService,
		private ConfigService $configService,
		private ActorsRequest $actorsRequest,
		private LoggerInterface $logger,
	) {
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

		// TODO: utiliser AP::instance()->getInterfaceFromType(Activity::TYPE)->save($item);

		$this->saveActivity($activity);

		return $this->request($activity);
	}

	/**
	 * @param Person $actor
	 * @param ACore $item
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function updateActivity(Person $actor, ACore $item): string {
		$update = new Update();
		$item->setParent($update);

		$update->setObject($item);
		$update->setId($item->getId() . '/activity#update');
		$update->setInstancePaths($item->getInstancePaths());

		$update->setActor($actor);
		$this->signatureService->signObject($actor, $update);

		return $this->request($update);
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

		// A recipient may only pass an activity on (AP §7.1.2) if it carries the
		// author's own signature over the document. Unsigned, a Delete of a
		// reply cannot travel the way the reply itself did, so the post stays
		// visible on every instance that only ever received it forwarded.
		try {
			$this->signatureService->signObject(
				$this->actorsRequest->getFromId($delete->getActorId()), $delete
			);
		} catch (Exception $e) {
			$this->logger->notice('a Delete goes out without a linked-data signature', [
				'activity' => $delete->getId(),
				'actor' => $delete->getActorId(),
				'exception' => $e,
			]);
		}

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
				$interface = AP::instance()->getInterfaceFromType($request);

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
	 * Whether an HTTP status from a peer is worth trying again later.
	 *
	 * 408 and 429 are explicitly temporary, and any 5xx is the peer's own
	 * problem rather than something wrong with what we sent. Everything else
	 * in the 4xx range means this activity will never be accepted, so there is
	 * nothing to gain by keeping it queued.
	 */
	private function isTransientHttpStatus(int $status): bool {
		return $status === Http::STATUS_REQUEST_TIMEOUT
			|| $status === Http::STATUS_TOO_MANY_REQUESTS
			|| $status >= Http::STATUS_INTERNAL_SERVER_ERROR;
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

		$url = $queue->getInstance()->getUri();
		$body = $this->bodyFromQueue($queue);

		try {
			$headers = $this->signatureService->signRequest($url, $body, $queue);
			$this->curlService->retrieveJson(
				$this->methodFromQueue($queue),
				$url,
				['headers' => $headers, 'body' => $body, 'timeout' => $queue->getTimeout()]
			);
			$this->requestQueueService->endRequest($queue, true);
		} catch (UnauthorizedFediverseException|RequestResultNotJsonException $e) {
			$this->requestQueueService->endRequest($queue, true);
		} catch (RequestContentException $e) {
			// The peer answered, but not with a 2xx. Whether that is worth
			// retrying depends entirely on the status: a 503 during an upgrade
			// or a 429 from a rate limiter is temporary and used to cost us
			// every activity queued for that instance, deleted on the spot.
			if ($this->isTransientHttpStatus($e->getCode())) {
				$this->logger->notice(
					'Temporary error while managing request: HTTP ' . $e->getCode() . ' - '
					. $url . ' - ' . $e->getMessage()
				);
				$this->requestQueueService->endRequest($queue, false);
				$this->failInstances[] = $host;

				return;
			}

			$this->logger->notice(
				'Permanent error while managing request: HTTP ' . $e->getCode() . ' - '
				. $url . ' - ' . $e->getMessage()
			);
			$this->requestQueueService->deleteRequest($queue);
		} catch (ActorDoesNotExistException|RequestResultSizeException $e) {
			$this->logger->notice(
				'Error while managing request: ' . $url . ' ' . get_class($e) . ': '
				. $e->getMessage()
			);
			$this->requestQueueService->deleteRequest($queue);
		} catch (RequestNetworkException|RequestServerException $e) {
			$this->logger->notice(
				'Temporary error while managing request: RequestServerException - ' . $url
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
					$instancePaths
						= array_merge($instancePaths, $this->generateInstancePathsFollowers($instancePath));
					break;

				case InstancePath::TYPE_ALL:
					$instancePaths = array_merge($instancePaths, $this->generateInstancePathsAll());
					break;

				default:
					$instancePaths[] = $instancePath;
					break;
			}
		}

		return array_values(array_filter($instancePaths, fn (InstancePath $path): bool => !$this->isOurs($path)));
	}

	/**
	 * Whether a delivery is addressed to this very instance.
	 *
	 * Everyone here already has the activity: recipients are written into
	 * social_stream_dest when the item is saved, which is what puts it in a
	 * local timeline — the HTTP round trip would only hand us back something we
	 * wrote ourselves. Worse, it is a request the server has to be able to make
	 * to its own public address, which behind a reverse proxy, split-horizon
	 * DNS or an SSRF guard it often cannot: the delivery then fails fifteen
	 * times and is dropped, while remote instances queue up behind it.
	 */
	private function isOurs(InstancePath $instancePath): bool {
		try {
			$local = strtolower($this->configService->getCloudHost());
		} catch (SocialAppConfigException $e) {
			// nothing configured to compare against; send it and find out
			return false;
		}

		return $local !== '' && strtolower($instancePath->getAddress()) === $local;
	}

	/**
	 * @param InstancePath $instancePath
	 *
	 * @return InstancePath[]
	 */
	private function generateInstancePathsFollowers(InstancePath $instancePath): array {
		$instancePaths = [];

		// One row per distinct inbox, resolved in the database. This used to
		// hydrate every follower into a Follow with a Person and its details
		// just to read one string off each — a popular local actor's every post
		// loaded its whole follower list into PHP memory, while the number of
		// inboxes involved is the number of *instances*, not of followers.
		//
		// The shared inbox is used where the remote publishes one and its
		// personal inbox otherwise: `endpoints.sharedInbox` is optional, and
		// using the empty string unconditionally aimed the delivery at host ''
		// — and, because the deduplication then treated '' as an inbox already
		// seen, dropped every follower after the first one on any such instance.
		foreach ($this->followsRequest->getFollowerInboxes($instancePath->getUri()) as $inbox) {
			$instancePaths[] = new InstancePath(
				$inbox, InstancePath::TYPE_GLOBAL, $instancePath->getPriority()
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

	/**
	 * Whether the queued delivery is a POST. Every row that reaches the queue
	 * is addressed at an inbox or a shared inbox, so in practice they all are;
	 * the other InstancePath types are expanded into those before queueing.
	 */
	private function methodFromQueue(RequestQueue $queue): string {
		$type = $queue->getInstance()->getType();

		return in_array($type, [
			InstancePath::TYPE_INBOX,
			InstancePath::TYPE_GLOBAL,
			InstancePath::TYPE_FOLLOWERS,
		], true) ? 'post' : 'get';
	}

	/**
	 * The bytes the delivery puts on the wire: the stored activity, decoded and
	 * re-encoded with unescaped slashes, which is what the transport has always
	 * sent and therefore what the digest has always covered.
	 */
	private function bodyFromQueue(RequestQueue $queue): string {
		return (string)json_encode(json_decode($queue->getActivity(), true), JSON_UNESCAPED_SLASHES);
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

			$service = AP::instance()->getInterfaceForItem($item);
			$service->save($item);
		} catch (ItemUnknownException $e) {
		} catch (ItemAlreadyExistsException $e) {
		}
	}
}
