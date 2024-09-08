<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AP;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\RetrieveAccountFormatException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\Relationship;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class FollowService {
	use TArrayTools;


	private IURLGenerator $urlGenerator;
	private FollowsRequest $followsRequest;
	private ActivityService $activityService;
	private CacheActorService $cacheActorService;
	private ConfigService $configService;
	private LoggerInterface $logger;
	private ?Person $viewer = null;


	/**
	 * FollowService constructor.
	 *
	 * @param FollowsRequest $followsRequest
	 * @param ActivityService $activityService
	 * @param CacheActorService $cacheActorService
	 * @param ConfigService $configService
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		IURLGenerator $urlGenerator,
		FollowsRequest $followsRequest,
		ActivityService $activityService,
		CacheActorService $cacheActorService,
		ConfigService $configService,
		LoggerInterface $logger
	) {
		$this->urlGenerator = $urlGenerator;
		$this->followsRequest = $followsRequest;
		$this->activityService = $activityService;
		$this->cacheActorService = $cacheActorService;
		$this->configService = $configService;
		$this->logger = $logger;
	}


	/**
	 * @param Person $viewer
	 */
	public function setViewer(Person $viewer) {
		$this->viewer = $viewer;
		$this->followsRequest->setViewer($viewer);
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
	 * @throws UnauthorizedFediverseException
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
		} catch (FollowNotFoundException $e) {
			$this->followsRequest->save($follow);

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
	 * @throws UnauthorizedFediverseException
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
		} catch (FollowNotFoundException $e) {
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
			'follower' => false,
			'following' => false
		];

		try {
			$this->followsRequest->getByPersons($local->getId(), $actor->getId());
			$links['following'] = true;
		} catch (FollowNotFoundException $e) {
		}

		try {
			$this->followsRequest->getByPersons($actor->getId(), $local->getId());
			$links['follower'] = true;
		} catch (FollowNotFoundException $e) {
		}

		return $links;
	}


	/**
	 * @param Person $actor
	 *
	 * @return Follow[]
	 *
	 * @psalm-return array<Follow>
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
		$collection->setTotalItems($this->getInt('followers', $actor->getDetails('count')));

		$first = $this->urlGenerator->linkToRouteAbsolute(
			'social.ActivityPub.followers',
			['username' => $actor->getPreferredUsername()]
		)
				 . '?page=1';
		$collection->setFirst($first);

		return $collection;
	}


	/**
	 * @param Person $actor
	 *
	 * @return Follow[]
	 *
	 * @psalm-return array<Follow>
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
		$collection->setId($actor->getFollowing());
		$collection->setTotalItems($this->getInt('following', $actor->getDetails('count')));

		$first = $this->urlGenerator->linkToRouteAbsolute(
			'social.ActivityPub.following',
			['username' => $actor->getPreferredUsername()]
		)
				 . '?page=1';
		$collection->setFirst($first);

		return $collection;
	}


	/**
	 * @param string $recipient
	 *
	 * @return Follow[]
	 */
	public function getFollowersFromFollowId(string $recipient): array {
		return $this->followsRequest->getFollowersByFollowId($recipient);
	}

	/**
	 * @return Relationship[]
	 */
	public function getRelationships(array $nids): array {
		$actorNids = $relationships = [];

		// retrieve actorIds from list of Nid
		foreach ($this->cacheActorService->getFromNids($nids) as $actor) {
			$actorNids[$actor->getNid()] = $actor->getId();
		}

		foreach ($actorNids as $actorNid => $actorId) {
			if ($actorNid === $this->viewer->getNid()) {
				continue; // ignore current session
			}

			$relationships[] = $this->generateRelationship($actorNid, $this->viewer->getId(), $actorId);
		}

		return $relationships;
	}

	/**
	 * @param int $nid
	 * @param string $viewerId
	 * @param string $actorId
	 *
	 * @return Relationship
	 */
	private function generateRelationship(int $nid, string $viewerId, string $actorId): Relationship {
		$relationship = new Relationship($nid);

		try {
			$follow = $this->followsRequest->getByPersons($viewerId, $actorId);
			if ($follow->isAccepted()) {
				$relationship->setFollowing(true);
			} else {
				$relationship->setRequested(true);
			}
		} catch (FollowNotFoundException $e) {
		}

		try {
			$follow = $this->followsRequest->getByPersons($actorId, $viewerId);
			if ($follow->isAccepted()) {
				$relationship->setFollowedBy(true);
			}
		} catch (FollowNotFoundException $e) {
		}

		return $relationship;
	}
}
