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


use daita\MySmallPhpTools\Exceptions\RequestContentException;
use daita\MySmallPhpTools\Exceptions\RequestNetworkException;
use daita\MySmallPhpTools\Exceptions\RequestResultNotJsonException;
use daita\MySmallPhpTools\Exceptions\RequestResultSizeException;
use daita\MySmallPhpTools\Exceptions\RequestServerException;
use daita\MySmallPhpTools\Model\Request;
use daita\MySmallPhpTools\Traits\TArrayTools;
use Exception;
use OCA\Social\AP;
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


/**
 * Class ActivityService
 *
 * @package OCA\Social\Service
 */
class ActivityService {


	use TArrayTools;


	const TIMEOUT_LIVE = 3;
	const TIMEOUT_ASYNC = 10;
	const TIMEOUT_SERVICE = 30;


	/** @var StreamRequest */
	private $streamRequest;

	/** @var FollowsRequest */
	private $followsRequest;

	/** @var SignatureService */
	private $signatureService;

	/** @var RequestQueueService */
	private $requestQueueService;

	/** @var AccountService */
	private $accountService;

	/** @var ConfigService */
	private $configService;

	/** @var CurlService */
	private $curlService;

	/** @var MiscService */
	private $miscService;


	/** @var array */
	private $failInstances;


	/**
	 * ActivityService constructor.
	 *
	 * @param StreamRequest $streamRequest
	 * @param FollowsRequest $followsRequest
	 * @param SignatureService $signatureService
	 * @param RequestQueueService $requestQueueService
	 * @param AccountService $accountService
	 * @param CurlService $curlService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		StreamRequest $streamRequest, FollowsRequest $followsRequest,
		SignatureService $signatureService, RequestQueueService $requestQueueService,
		AccountService $accountService, CurlService $curlService, ConfigService $configService,
		MiscService $miscService
	) {
		$this->streamRequest = $streamRequest;
		$this->followsRequest = $followsRequest;
		$this->requestQueueService = $requestQueueService;
		$this->accountService = $accountService;
		$this->signatureService = $signatureService;
		$this->curlService = $curlService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param Person $actor
	 * @param ACore $item
	 * @param ACore $activity
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function createActivity(Person $actor, ACore $item, ACore &$activity = null): string {

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
	 * @param ACore $activity
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function request(ACore $activity): string {
//		$this->saveActivity($activity);

		$author = $this->getAuthorFromItem($activity);
		$instancePaths = $this->generateInstancePaths($activity);
		$token =
			$this->requestQueueService->generateRequestQueue($instancePaths, $activity, $author);

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

		$requests =
			$this->requestQueueService->getRequestFromToken($token, RequestQueue::STATUS_STANDBY);
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
			return;
		}

		$request = $this->generateRequestFromQueue($queue);

		try {
			$this->signatureService->signRequest($request, $queue);
			$this->curlService->retrieveJson($request);
			$this->requestQueueService->endRequest($queue, true);
		} catch (UnauthorizedFediverseException | RequestResultNotJsonException $e) {
			$this->requestQueueService->endRequest($queue, true);
		} catch (ActorDoesNotExistException | RequestContentException | RequestResultSizeException $e) {
			$this->miscService->log(
				'Error while managing request: ' . json_encode($request) . ' ' . get_class($e) . ': '
				. $e->getMessage(), 1
			);
			$this->requestQueueService->deleteRequest($queue);
		} catch (RequestNetworkException | RequestServerException $e) {
			$this->miscService->log(
				'Temporary error while managing request: RequestServerException - ' . json_encode($request)
				. ' - ' . get_class($e) . ': ' . $e->getMessage(), 1
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
			if ($instancePath->getType() === InstancePath::TYPE_FOLLOWERS) {
				$instancePaths = array_merge(
					$instancePaths, $this->generateInstancePathsFollowers($instancePath)
				);
			} else {
				$instancePaths[] = $instancePath;
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
//			$result[] = $this->generateRequest(
//				new InstancePath($sharedInbox, InstancePath::TYPE_GLOBAL), $activity
//			);
		}

		return $instancePaths;
	}


	/**
	 * @param RequestQueue $queue
	 *
	 * @return Request
	 */
	private function generateRequestFromQueue(RequestQueue $queue): Request {
		$path = $queue->getInstance();

		$requestType = Request::TYPE_GET;
		if ($path->getType() === InstancePath::TYPE_INBOX
			|| $path->getType() === InstancePath::TYPE_GLOBAL
			|| $path->getType() === InstancePath::TYPE_FOLLOWERS) {
			$requestType = Request::TYPE_POST;
		}

		$request = new Request($path->getPath(), $requestType);
		$request->setTimeout($queue->getTimeout());
		$request->setDataJson($queue->getActivity());
		$request->setAddress($path->getAddress());
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

