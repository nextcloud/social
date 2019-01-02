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


namespace OCA\Social\Interfaces\Activity;


use daita\MySmallPhpTools\Exceptions\MalformedArrayException;
use Exception;
use OCA\Social\AP;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\FollowDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\Request410Exception;
use OCA\Social\Exceptions\RequestException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnknownItemException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Follow;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;


class FollowInterface implements IActivityPubInterface {


	/** @var FollowsRequest */
	private $followsRequest;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var ActivityService */
	private $activityService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * NoteInterface constructor.
	 *
	 * @param FollowsRequest $followsRequest
	 * @param CacheActorService $cacheActorService
	 * @param ActivityService $activityService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		FollowsRequest $followsRequest, CacheActorService $cacheActorService,
		ActivityService $activityService, ConfigService $configService, MiscService $miscService
	) {
		$this->followsRequest = $followsRequest;
		$this->cacheActorService = $cacheActorService;
		$this->activityService = $activityService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param ACore $item
	 */
	public function processResult(ACore $item) {
	}


	/**
	 * @param Follow $follow
	 */
	public function confirmFollowRequest(Follow $follow) {
		try {
			$remoteActor = $this->cacheActorService->getFromId($follow->getActorId());

			$accept = new Accept();
			$accept->setUrlCloud($this->configService->getCloudAddress());
			$accept->generateUniqueId('#accept/follows');
			$accept->setActorId($follow->getObjectId());
			$accept->setObject($follow);
			$follow->setParent($accept);

			$accept->addInstancePath(
				new InstancePath(
					$remoteActor->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP
				)
			);

			$this->activityService->request($accept);
			$this->followsRequest->accepted($follow);

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
	 * @param ACore $follow
	 *
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws Request410Exception
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 * @throws RedundancyLimitException
	 * @throws UnknownItemException
	 */
	public function processIncomingRequest(ACore $follow) {
		/** @var Follow $follow */
		$follow->checkOrigin($follow->getActorId());

		try {
			$knownFollow = $this->followsRequest->getByPersons(
				$follow->getActorId(), $follow->getObjectId()
			);

			if ($knownFollow->getId() === $follow->getId() && !$knownFollow->isAccepted()) {
				$this->confirmFollowRequest($follow);
			}
		} catch (FollowDoesNotExistException $e) {
			$actor = $this->cacheActorService->getFromId($follow->getObjectId());

			if ($actor->isLocal()) {
				$follow->setFollowId($actor->getFollowers());
				$this->followsRequest->save($follow);
				$this->confirmFollowRequest($follow);
			}
		}

	}


	/**
	 * @param string $id
	 *
	 * @return ACore
	 * @throws ItemNotFoundException
	 */
	public function getItemById(string $id): ACore {
		throw new ItemNotFoundException();
	}


	/**
	 * @param ACore $activity
	 * @param ACore $item
	 *
	 * @throws InvalidOriginException
	 */
	public function activity(Acore $activity, ACore $item) {
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
	 * @param ACore $item
	 */
	public function save(ACore $item) {
	}


	/**
	 * @param ACore $item
	 */
	public function delete(ACore $item) {
	}


	/**
	 * @param Follow $follow
	 *
	 * @throws UnknownItemException
	 */
	private function generateNotification(Follow $follow) {
		/** @var SocialAppNotificationInterface $notificationInterface */
		$notificationInterface =
			AP::$activityPub->getInterfaceFromType(SocialAppNotification::TYPE);

		$notification = new SocialAppNotification();
		$notification->setId($follow->getId() . '/notification')
					 ->setSummary('{actor} is following you')
					 ->setTo($follow->getObjectId())
					 ->setLocal(true)
					 ->setAttributedTo($follow->getActorId());
		$notificationInterface->save($notification);
	}

}

