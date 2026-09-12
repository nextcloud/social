<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\ActorRelationRequest;
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
use OCA\Social\Interfaces\Object\FollowInterface;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\ActivityPub\OrderedCollectionPage;
use OCA\Social\Model\ActorRelation;
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
use Throwable;

class FollowService {
	use TArrayTools;

	private IURLGenerator $urlGenerator;
	private FollowsRequest $followsRequest;
	private ActorRelationRequest $actorRelationRequest;
	private ActivityService $activityService;
	private CacheActorService $cacheActorService;
	private ConfigService $configService;
	private FollowInterface $followInterface;
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
		ActorRelationRequest $actorRelationRequest,
		ActivityService $activityService,
		CacheActorService $cacheActorService,
		ConfigService $configService,
		FollowInterface $followInterface,
		private ModerationService $moderationService,
		private AccountRelationService $accountRelationService,
		LoggerInterface $logger,
	) {
		$this->urlGenerator = $urlGenerator;
		$this->followsRequest = $followsRequest;
		$this->actorRelationRequest = $actorRelationRequest;
		$this->activityService = $activityService;
		$this->cacheActorService = $cacheActorService;
		$this->configService = $configService;
		$this->followInterface = $followInterface;
		$this->logger = $logger;
	}

	/**
	 * The accounts whose follows towards the viewer wait for approval.
	 *
	 * @return Person[]
	 */
	public function getPendingRequests(): array {
		$pending = [];
		foreach ($this->followsRequest->getPendingByObjectId($this->viewer->getId()) as $follow) {
			try {
				$pending[] = $this->cacheActorService->getFromId($follow->getActorId());
			} catch (Exception $e) {
			}
		}

		return $pending;
	}

	/**
	 * Approves a pending follow request: federates the Accept and marks the row.
	 *
	 * @throws FollowNotFoundException when there is no pending follow from that account
	 */
	public function authorizeFollowRequest(Person $follower): void {
		$follow = $this->followsRequest->getByPersons($follower->getId(), $this->viewer->getId());
		if ($follow->isAccepted()) {
			return; // already following: authorize is idempotent
		}

		$this->followInterface->confirmFollowRequest($follow);
	}

	/**
	 * Rejects a pending follow request: federates the Reject and drops the row.
	 *
	 * @throws FollowNotFoundException when there is no pending follow from that account
	 */
	public function rejectFollowRequest(Person $follower): void {
		$follow = $this->followsRequest->getByPersons($follower->getId(), $this->viewer->getId());

		$this->followInterface->rejectFollowRequest($follow);
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
		$this->moderationService->assertNotSuspended($actor->getId());
		$this->logger->debug('FollowService::followAccount called', [
			'actor' => $actor->getId(),
			'account' => $account,
		]);

		$remoteActor = $this->cacheActorService->getFromAccount($account);
		$this->logger->debug('FollowService::followAccount - remote actor resolved', [
			'remoteId' => $remoteActor->getId(),
			'remoteNid' => $remoteActor->getNid(),
		]);

		if ($remoteActor->getId() === $actor->getId()) {
			$this->logger->warning('FollowService::followAccount - same account');
			throw new FollowSameAccountException("Don't follow yourself, be your own lead");
		}

		/** @var Follow $follow */
		$follow = AP::instance()->getItemFromType(Follow::TYPE);
		$follow->generateUniqueId();
		$follow->setActorId($actor->getId());
		$follow->setObjectId($remoteActor->getId());
		$follow->setFollowId($remoteActor->getFollowers());

		try {
			$this->followsRequest->getByPersons($actor->getId(), $remoteActor->getId());
			$this->logger->info('FollowService::followAccount - already following', [
				'actor' => $actor->getId(),
				'target' => $remoteActor->getId(),
			]);
		} catch (FollowNotFoundException $e) {
			$this->followsRequest->save($follow);
			$this->logger->info('FollowService::followAccount - saved new follow', [
				'followId' => $follow->getId(),
				'actor' => $actor->getId(),
				'object' => $remoteActor->getId(),
			]);

			$follow->addInstancePath(
				new InstancePath(
					$remoteActor->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP
				)
			);
			try {
				$this->activityService->request($follow);
				$this->logger->info('FollowService::followAccount - activity queued');
			} catch (Throwable $e) {
				$this->logger->error('FollowService::followAccount - failed to queue activity', [
					'error' => $e->getMessage(),
				]);
			}
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

			$undo = AP::instance()->getItemFromType(Undo::TYPE);
			$follow->setParent($undo);
			// hung off the local actor, not the cloud root: see
			// ACore::generateUniqueIdFromActor()
			$undo->generateUniqueIdFromActor($actor->getId(), 'undo/follows');
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
		return OrderedCollection::paged(
			$actor->getFollowers(),
			$this->getInt('followers', $actor->getDetails('count')),
			$this->collectionRoute('social.ActivityPub.followers', $actor)
		);
	}

	/**
	 * One page of the followers collection.
	 *
	 * The collection has always advertised `first` as `?page=1`, but nothing
	 * read the parameter: `?page=1` returned the identical collection, whose
	 * `first` pointed at itself. A consumer following `first` either looped or
	 * gave up, so nobody could enumerate a local actor's followers — which is
	 * how another instance discovers who to deliver to when its own record is
	 * incomplete, and how account migration tools rebuild a follower list.
	 */
	public function getFollowersPage(Person $actor, int $page): OrderedCollectionPage {
		return OrderedCollectionPage::of(
			$actor->getFollowers(),
			$this->collectionRoute('social.ActivityPub.followers', $actor),
			$page,
			array_map(
				static fn (Follow $follow): string => $follow->getActorId(),
				$this->followsRequest->getFollowersByActorId(
					$actor->getId(),
					OrderedCollection::PAGE_SIZE,
					($page - 1) * OrderedCollection::PAGE_SIZE
				)
			)
		);
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
		return OrderedCollection::paged(
			$actor->getFollowing(),
			$this->getInt('following', $actor->getDetails('count')),
			$this->collectionRoute('social.ActivityPub.following', $actor)
		);
	}

	/** One page of the following collection. See getFollowersPage(). */
	public function getFollowingPage(Person $actor, int $page): OrderedCollectionPage {
		return OrderedCollectionPage::of(
			$actor->getFollowing(),
			$this->collectionRoute('social.ActivityPub.following', $actor),
			$page,
			array_map(
				static fn (Follow $follow): string => $follow->getObjectId(),
				$this->followsRequest->getFollowingByActorId(
					$actor->getId(),
					OrderedCollection::PAGE_SIZE,
					($page - 1) * OrderedCollection::PAGE_SIZE
				)
			)
		);
	}

	private function collectionRoute(string $route, Person $actor): string {
		return $this->urlGenerator->linkToRouteAbsolute(
			$route, ['username' => $actor->getPreferredUsername()]
		);
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
	public function getRelationships(array $ids): array {
		$actorNids = $relationships = [];

		// try to resolve actors by their id (could be nid or url)
		$nids = [];
		foreach ($ids as $id) {
			if (is_numeric($id) && (int)$id > 0) {
				$nids[] = (int)$id;
			}
		}

		// retrieve actorIds from list of Nid
		foreach ($this->cacheActorService->getFromNids($nids) as $actor) {
			$actorNids[$actor->getNid()] = $actor->getId();
		}

		// if any ids weren't found by nid, try by url
		foreach ($ids as $id) {
			if (is_numeric($id) && (int)$id > 0 && isset($actorNids[(int)$id])) {
				continue;
			}
			try {
				$actor = $this->cacheActorService->getFromId((string)$id);
				$actorNids[$actor->getNid()] = $actor->getId();
			} catch (CacheActorDoesNotExistException $e) {
				$this->logger->debug('getRelationships - actor not found by id', ['id' => $id]);
			}
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
	/**
	 * The viewer's relationship with one resolved actor. Unlike getRelationships()
	 * this takes the Person directly, so it always returns an entry (the block/mute
	 * endpoints need the updated relationship back even right after the change).
	 */
	public function getRelationshipWith(Person $target): Relationship {
		return $this->generateRelationship($target->getNid(), $this->viewer->getId(), $target->getId());
	}

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
			$this->logger->debug('generateRelationship - not following', [
				'viewerId' => $viewerId,
				'actorId' => $actorId,
				'nid' => $nid,
			]);
		}

		try {
			$follow = $this->followsRequest->getByPersons($actorId, $viewerId);
			if ($follow->isAccepted()) {
				$relationship->setFollowedBy(true);
			} else {
				// the row behind /api/v1/follow_requests: this account has asked
				// to follow the viewer and is waiting to be let in, which is
				// what a client shows the approve/reject buttons for
				$relationship->setRequestedBy(true);
			}
		} catch (FollowNotFoundException $e) {
		}

		foreach ($this->actorRelationRequest->getBetween($viewerId, $actorId) as $relation) {
			switch ($relation->getType()) {
				case ActorRelation::TYPE_BLOCK:
					$relationship->setBlocking(true);
					break;
				case ActorRelation::TYPE_BLOCKED_BY:
					$relationship->setBlockedBy(true);
					break;
				case ActorRelation::TYPE_MUTE:
					$relationship->setMuting(true);
					$relationship->setMutingNotifications($relation->isNotifications());
					break;
				case AccountRelationService::TYPE_ENDORSE:
					// the row is already in hand; reading it again would be a
					// query for something this loop just read
					$relationship->setEndorsed(true);
					break;
			}
		}

		// `domain_blocking`, `note` and the expiry of a mute are each a lookup
		// on (viewer, account), and a mute that has run out is still a row: the
		// read is what stops reporting it
		$this->accountRelationService->decorate($relationship, $viewerId, $actorId);

		return $relationship;
	}
}
