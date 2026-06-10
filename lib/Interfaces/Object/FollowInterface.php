<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Object;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\MiscService;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;

/**
 * Class FollowInterface
 *
 * @package OCA\Social\Interfaces\Object
 */
class FollowInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	private FollowsRequest $followsRequest;
	private CacheActorService $cacheActorService;
	private AccountService $accountService;
	private ActivityService $activityService;
	private MiscService $miscService;

	public function __construct(
		FollowsRequest $followsRequest, CacheActorService $cacheActorService,
		AccountService $accountService, ActivityService $activityService,
		MiscService $miscService,
	) {
		$this->followsRequest = $followsRequest;
		$this->cacheActorService = $cacheActorService;
		$this->accountService = $accountService;
		$this->activityService = $activityService;
		$this->miscService = $miscService;
	}

	public function confirmFollowRequest(Follow $follow): void {
		try {
			$remoteActor = $this->cacheActorService->getFromId($follow->getActorId());

			$accept = AP::$activityPub->getItemFromType(Accept::TYPE);
			$accept->generateUniqueId('#accept/follows');
			$accept->setActorId($follow->getObjectId());
			$accept->setObject($follow);
			//			$follow->setParent($accept);

			$accept->addInstancePath(
				new InstancePath(
					$remoteActor->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP
				)
			);

			$this->activityService->request($accept);
			$this->followsRequest->accepted($follow);

			$actor = $this->cacheActorService->getFromId($follow->getObjectId());
			$this->accountService->cacheLocalActorDetailCount($actor);

			$this->generateNotification($follow);
		} catch (Exception $e) {
			$this->miscService->log(
				'exception while confirmFollowRequest: ' . get_class($e) . ' - ' . $e->getMessage(),
				2
			);
		}
	}


	/**
	 * Process an incoming Follow activity (remote user wants to follow a local user).
	 *
	 * Flow:
	 *  1. Verify the Follow actor's origin matches the request origin.
	 *  2. Check if we already have this follow in DB.
	 *  3a. If new: save it, accept it, send Accept activity back.
	 *  3b. If existing but not yet accepted: (re-)send Accept.
	 *      IMPORTANT: The embedded Follow's id differs from our local db id
	 *      (remote uses their own id), so match by actor+object pair.
	 *
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws RequestResultNotJsonException
	 * @throws Exception
	 */
	public function processIncomingRequest(ACore $item): void {
		/** @var Follow $follow */
		$follow = $item;
		$follow->checkOrigin($follow->getActorId());

		try {
			$knownFollow = $this->followsRequest->getByPersons($follow->getActorId(), $follow->getObjectId());
			if (!$knownFollow->isAccepted()) {
				$this->confirmFollowRequest($follow);
			}
		} catch (FollowNotFoundException $e) {
			$actor = $this->cacheActorService->getFromId($follow->getObjectId());

			if ($actor->isLocal()) {
				$follow->setFollowId($actor->getFollowers());
				$this->followsRequest->save($follow);
				$this->confirmFollowRequest($follow);
			}
		}
	}

	/**
	 * Handle activities wrapping a Follow (Accept, Reject, Undo).
	 *
	 * This is called when an Accept/Reject/Undo activity targeting a Follow arrives.
	 *
	 * For Accept(ourFollow): remote accepted our follow → mark accepted in DB.
	 *   origin check: the Accept comes from the followed actor's server, and
	 *   $item->getObjectId() is the followed actor → host must match origin.
	 *
	 * For Reject(ourFollow): remote rejected our follow → delete from DB.
	 *
	 * For Undo(theirFollow): remote unfollowed us → delete from DB.
	 *
	 * @param ACore $activity The wrapping activity (Accept/Reject/Undo)
	 * @param ACore $item The Follow object inside the activity
	 *
	 * @throws InvalidOriginException
	 */
	public function activity(Acore $activity, ACore $item): void {
		/** @var Follow $item */
		if ($activity->getType() === Undo::TYPE) {
			$activity->checkOrigin($item->getId());
			$activity->checkOrigin($item->getActorId());
			$this->followsRequest->delete($item);
		}

		if ($activity->getType() === Reject::TYPE) {
			$activity->checkOrigin($item->getObjectId());
			$this->followsRequest->delete($item);
		}

		if ($activity->getType() === Accept::TYPE) {
			$activity->checkOrigin($item->getObjectId());
			$this->followsRequest->accepted($item);
		}
	}

	/**
	 * @throws SocialAppConfigException|ItemAlreadyExistsException|ItemUnknownException
	 */
	private function generateNotification(Follow $follow): void {
		/** @var SocialAppNotificationInterface $notificationInterface */
		$notificationInterface = AP::$activityPub->getInterfaceFromType(SocialAppNotification::TYPE);

		try {
			$follower = $this->cacheActorService->getFromId($follow->getActorId());
		} catch (Exception $e) {
			return;
		}

		/** @var SocialAppNotification $notification */
		$notification = AP::$activityPub->getItemFromType(SocialAppNotification::TYPE);
		$notification->setDetail('url', $follower->getId());
		$notification->setDetail('account', $follower->getAccount());
		$notification->setDetailItem('actor', $follower);
		$notification->setAttributedTo($follow->getActorId())
			->setId($follow->getId() . '/notification')
			->setSubType(Follow::TYPE)
			->setActorId($follower->getId())
			->setSummary('{account} is following you')
			->setTo($follow->getObjectId())
			->setLocal(true);

		$notificationInterface->save($notification);
	}
}
