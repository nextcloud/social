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

use ActivityPhp\Type;
use OCA\Social\AP;
use OCA\Social\Entity\Account;
use OCA\Social\Entity\Follow;
use OCA\Social\Entity\FollowRequest;
use OCA\Social\Service\Feed\FeedManager;
use OCP\DB\ORM\IEntityManager;
use OCP\DB\ORM\IEntityRepository;

class FollowOption {
	/**
	 * Show reblog of the account
	 */
	public bool $showReblogs = true;

	/**
	 * Notify about new posts
	 */
	public bool $notify = false;

	static public function default(): self {
		return new FollowOption();
	}
}

class FollowService {
	private IEntityManager $entityManager;
	/** @var IEntityRepository<Follow> $followRepository */
	private IEntityRepository $followRepository;
	/** @var IEntityRepository<FollowRequest> $followRepository */
	private IEntityRepository $followRequestRepository;
	private FeedManager $feedManager;

	public function __construct(IEntityManager $entityManager, FeedManager $feedManager) {
		$this->entityManager = $entityManager;
		$this->followRepository = $entityManager->getRepository(Follow::class);
		$this->followRepository = $entityManager->getRepository(FollowRequest::class);
		$this->feedManager = $feedManager;
	}

	public function follow(Account $sourceAccount, Account $targetAccount, FollowOption $option): void {
		if ($sourceAccount->following($targetAccount)) {
			$this->updateFollow($sourceAccount, $targetAccount, $option->notify, $option->showReblogs);
			return;
		} elseif ($sourceAccount->followRequested($targetAccount)) {
			$this->updateFollowRequest($sourceAccount, $targetAccount, $option->notify, $option->showReblogs);
			return;
		}

		if ($targetAccount->isLocked() || !$targetAccount->isLocal()) {
			$this->requestFollow($sourceAccount, $targetAccount);
		} else {
			$this->directFollow($sourceAccount, $targetAccount);
		}
	}

	private function updateFollow(Account $sourceAccount, Account $targetAccount, bool $notify, bool $showReblogs): void {
		/** @var Follow $follow */
		$follow = $this->followRepository->findOneBy([
			'account' => $sourceAccount,
			'targetAccount' => $targetAccount,
		]);
		assert($follow);

		$follow->setNotify($notify);
		$follow->setShowReblogs($showReblogs);
		$this->entityManager->persist($follow);
		$this->entityManager->flush();
	}

	private function updateFollowRequest(Account $sourceAccount, Account $targetAccount, bool $notify, bool $showReblogs): void {
		/** @var Follow $follow */
		$followRequest = $this->followRequestRepository->findOneBy([
			'account' => $sourceAccount,
			'targetAccount' => $targetAccount,
		]);
		assert($followRequest);

		$followRequest->setNotify($notify);
		$followRequest->setShowReblogs($showReblogs);
		$this->entityManager->persist($followRequest);
		$this->entityManager->flush();
	}

	private function directFollow(Account $sourceAccount, Account $targetAccount): Follow {
		$follow = $sourceAccount->follow($targetAccount);
		$this->entityManager->persist($follow);
		$this->entityManager->flush();

		// TODO Notify target account they got a new follower

		// Add statues of target user into source user timeline
		$this->feedManager->mergeIntoHome($targetAccount, $sourceAccount);

		return $follow;
	}

	private function requestFollow(Account $sourceAccount, Account $targetAccount) {
		if ($targetAccount->isLocal()) {
			// Just create an internal follow request
			$followRequest = $sourceAccount->requestFollow($targetAccount);
			$this->entityManager->persist($followRequest);
			$this->entityManager->flush();

			// TODO Notify target account they got a new follow request
		} else {
			$this->createRemoteFollowRequest($sourceAccount, $targetAccount);
		}
	}

	private function createRemoteFollowRequest(Account $sourceAccount, Account $targetAccount): void {
		/** @var Type\Extended\Activity\Follow $follow */
		$follow = Type::create('Follow', [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'actor' => $sourceAccount->getUri(),
			'object' => $targetAccount->getUri(),
		]);

		// TODO send follow request
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


	/**
	 * @param string $recipient
	 *
	 * @return Follow[]
	 */
	public function getFollowersFromFollowId(string $recipient): array {
		return $this->followsRequest->getFollowersByFollowId($recipient);
	}
}
