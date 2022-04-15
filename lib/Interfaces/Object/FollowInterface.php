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


namespace OCA\Social\Interfaces\Object;

use daita\MySmallPhpTools\Exceptions\MalformedArrayException;
use Exception;
use OCA\Social\AP;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use daita\MySmallPhpTools\Exceptions\RequestContentException;
use daita\MySmallPhpTools\Exceptions\RequestNetworkException;
use daita\MySmallPhpTools\Exceptions\RequestResultNotJsonException;
use daita\MySmallPhpTools\Exceptions\RequestResultSizeException;
use daita\MySmallPhpTools\Exceptions\RequestServerException;
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
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;

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
		 MiscService $miscService
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
	 * This method is called when saving the Follow object
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
			$knownFollow =
				$this->followsRequest->getByPersons($follow->getActorId(), $follow->getObjectId());

			if ($knownFollow->getId() === $follow->getId() && !$knownFollow->isAccepted()) {
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
	 * @param ACore $activity
	 * @param ACore $item
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
		$notificationInterface =
			AP::$activityPub->getInterfaceFromType(SocialAppNotification::TYPE);

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
