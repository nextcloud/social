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
use daita\MySmallPhpTools\Traits\TArrayTools;
use OCA\Social\AP;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\FollowDoesNotExistException;
use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\RequestContentException;
use OCA\Social\Exceptions\RequestResultNotJsonException;
use OCA\Social\Exceptions\RetrieveAccountFormatException;
use OCA\Social\Exceptions\RequestNetworkException;
use OCA\Social\Exceptions\RequestResultSizeException;
use OCA\Social\Exceptions\RequestServerException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\InstancePath;

class FollowService {


	use TArrayTools;


	/** @var FollowsRequest */
	private $followsRequest;

	/** @var ActivityService */
	private $activityService;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/** @var string */
	private $viewerId = '';


	/**
	 * FollowService constructor.
	 *
	 * @param FollowsRequest $followsRequest
	 * @param ActivityService $activityService
	 * @param CacheActorService $cacheActorService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		FollowsRequest $followsRequest, ActivityService $activityService,
		CacheActorService $cacheActorService,
		ConfigService $configService, MiscService $miscService
	) {
		$this->followsRequest = $followsRequest;
		$this->activityService = $activityService;
		$this->cacheActorService = $cacheActorService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $viewerId
	 */
	public function setViewerId(string $viewerId) {
		$this->viewerId = $viewerId;
		$this->followsRequest->setViewerId($viewerId);
	}

	public function getViewerId(): string {
		return $this->viewerId;
	}


	/**
	 * @param Person $actor
	 * @param string $account
	 *
	 * @throws CacheActorDoesNotExistException
	 * @throws FollowSameAccountException
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RetrieveAccountFormatException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws UrlCloudException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws RequestResultNotJsonException
	 */
	public function followAccount(Person $actor, string $account) {
		$remoteActor = $this->cacheActorService->getFromAccount($account);
		if ($remoteActor->getId() === $actor->getId()) {
			throw new FollowSameAccountException("Don't follow yourself, be your own lead");
		}

		/** @var Follow $follow */
		$follow = AP::$activityPub->getItemFromType(Follow::TYPE);
		$follow->generateUniqueId();
		$follow->setActorId($actor->getId());
		$follow->setObjectId($remoteActor->getId());
		$follow->setFollowId($remoteActor->getFollowers());

		try {
			$this->followsRequest->getByPersons($actor->getId(), $remoteActor->getId());
		} catch (FollowDoesNotExistException $e) {
			$this->followsRequest->save($follow);
			// TODO - Remove this auto-accepted.
			$this->followsRequest->accepted($follow);

			$follow->addInstancePath(
				new InstancePath(
					$remoteActor->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP
				)
			);
			$this->activityService->request($follow);
		}
	}


	/**
	 * @param Person $actor
	 * @param string $account
	 *
	 * @throws CacheActorDoesNotExistException
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RetrieveAccountFormatException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws UrlCloudException
	 * @throws RequestResultNotJsonException
	 */
	public function unfollowAccount(Person $actor, string $account) {
		$remoteActor = $this->cacheActorService->getFromAccount($account);

		try {
			$follow = $this->followsRequest->getByPersons($actor->getId(), $remoteActor->getId());
			$this->followsRequest->delete($follow);

			$undo = AP::$activityPub->getItemFromType(Undo::TYPE);
			$follow->setParent($undo);
			$undo->generateUniqueId('#undo/follows');
			$undo->setObject($follow);
			$undo->setActorId($actor->getId());

			$undo->addInstancePath(
				new InstancePath(
					$remoteActor->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP
				)
			);
			$this->activityService->request($undo);
		} catch (FollowDoesNotExistException $e) {
		}
	}


	/**
	 * @param Person $local
	 * @param Person $actor
	 *
	 * @return array
	 */
	public function getLinksBetweenPersons(Person $local, Person $actor): array {

		$links = [
			'follower'  => false,
			'following' => false
		];

		try {
			$this->followsRequest->getByPersons($local->getId(), $actor->getId());
			$links['following'] = true;
		} catch (FollowDoesNotExistException $e) {
		}

		try {
			$this->followsRequest->getByPersons($actor->getId(), $local->getId());
			$links['follower'] = true;
		} catch (FollowDoesNotExistException $e) {
		}

		return $links;
	}


	/**
	 * @param Person $actor
	 *
	 * @return Person[]
	 */
	public function getFollowers(Person $actor): array {
		return $this->followsRequest->getFollowersByActorId($actor->getId());
	}


	/**
	 * @param Person $actor
	 *
	 * @return OrderedCollection
	 */
	public function getFollowersCollection(Person $actor): OrderedCollection {
		$collection = new OrderedCollection();
		$collection->setId($actor->getFollowers());
		$collection->setTotalItems(20);
		$collection->setFirst('...');

		return $collection;
	}


	/**
	 * @param Person $actor
	 *
	 * @return Person[]
	 */
	public function getFollowing(Person $actor): array {
		return $this->followsRequest->getFollowingByActorId($actor->getId());
	}


	/**
	 * @param Person $actor
	 *
	 * @return OrderedCollection
	 */
	public function getFollowingCollection(Person $actor): OrderedCollection {
		$collection = new OrderedCollection();
//		$collection->setId($actor->getFollowers());
//		$collection->setTotalItems(20);
//		$collection->setFirst('...');

		return $collection;
	}

}

