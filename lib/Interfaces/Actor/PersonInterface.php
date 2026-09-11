<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Actor;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\FiltersRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Db\ReportsRequest;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Db\StreamActionsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class PersonService
 *
 * @package OCA\Social\Service\ActivityPub
 */
class PersonInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	use TArrayTools;

	private ActionsRequest $actionsRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private CacheDocumentsRequest $cacheDocumentsRequest;
	private FollowsRequest $followsRequest;
	private ActorRelationRequest $actorRelationRequest;
	private RequestQueueRequest $requestQueueRequest;
	private StreamRequest $streamRequest;
	private StreamDestRequest $streamDestRequest;
	private ActorService $actorService;
	private ConfigService $configService;

	public function __construct(
		ActionsRequest $actionsRequest,
		CacheActorsRequest $cacheActorsRequest,
		CacheDocumentsRequest $cacheDocumentsRequest,
		FollowsRequest $followsRequest,
		ActorRelationRequest $actorRelationRequest,
		RequestQueueRequest $requestQueueRequest,
		StreamRequest $streamRequest,
		StreamDestRequest $streamDestRequest,
		ActorService $actorService,
		ConfigService $configService,
		private StreamActionsRequest $streamActionsRequest,
		private ReportsRequest $reportsRequest,
		private FiltersRequest $filtersRequest,
		private ListsRequest $listsRequest,
		private ConversationsRequest $conversationsRequest,
	) {
		$this->actionsRequest = $actionsRequest;
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->cacheDocumentsRequest = $cacheDocumentsRequest;
		$this->followsRequest = $followsRequest;
		$this->actorRelationRequest = $actorRelationRequest;
		$this->requestQueueRequest = $requestQueueRequest;
		$this->streamRequest = $streamRequest;
		$this->streamDestRequest = $streamDestRequest;
		$this->actorService = $actorService;
		$this->configService = $configService;
	}

	/**
	 * @throws ItemNotFoundException
	 */
	public function getItem(ACore $item): ACore {
		throw new ItemNotFoundException();
	}

	/**
	 * @throws ItemNotFoundException
	 */
	public function getItemById(string $id): ACore {
		try {
			$actor = $this->cacheActorsRequest->getFromId($id);

			return $actor;
		} catch (CacheActorDoesNotExistException $e) {
			throw new ItemNotFoundException();
		}
	}

	/**
	 * @throws InvalidOriginException
	 */
	public function activity(Acore $activity, ACore $item): void {
		/** @var Person $item */
		$activity->checkOrigin($item->getId());

		switch ($activity->getType()) {
			case Update::TYPE:
				$this->updateActor($item, $activity);
				break;

			case Delete::TYPE:
				$this->delete($item);
				break;
		}
	}

	public function save(ACore $item): void {
		/** @var Person $person */
		$person = $item;
		try {
			$this->getItemById($person->getId());
			$this->actorService->update($person);
		} catch (ItemNotFoundException $e) {
			$this->actorService->save($person);
		}
	}

	public function delete(ACore $item): void {
		if (!($item instanceof Person)) {
			return;
		}

		$this->actionsRequest->deleteByActor($item->getId());
		$this->cacheActorsRequest->deleteCacheById($item->getId());
		$this->cacheDocumentsRequest->deleteByParent($item->getId());
		$this->requestQueueRequest->deleteByAuthor($item->getId());
		$this->followsRequest->deleteRelatedId($item->getId());
		$this->actorRelationRequest->deleteRelatedId($item->getId());
		// what this actor did to other people's posts — their own likes,
		// boosts, bookmarks and poll votes — which nothing else removed
		$this->streamActionsRequest->deleteByActor($item->getId());
		// the reports about them, and the ones they filed
		$this->reportsRequest->deleteRelatedId($item->getId());
		// the keyword filters and lists they made, which are theirs alone and
		// which nothing else removes
		$this->filtersRequest->deleteRelatedId($item->getId());
		$this->listsRequest->deleteRelatedId($item->getId());
		// how far they had read and dismissed their own conversations
		$this->conversationsRequest->deleteRelatedId($item->getId());
		// a moderation decision deliberately outlives the account: it is what
		// keeps a suspended account suspended if it comes back

		$this->deleteStreamFromActor($item);
	}

	/**
	 * @param Person $actor
	 */
	private function deleteStreamFromActor(Person $actor): void {
		// first, we delete all post generate by actor
		$this->streamRequest->deleteByAuthor($actor->getId());

		// then we look for link to the actor as dest
		foreach ($this->streamDestRequest->getRelatedToActor($actor) as $streamDest) {
			if ($streamDest->getType() !== 'recipient') {
				continue;
			}

			try {
				$stream = $this->streamRequest->getStream($streamDest->getStreamId());
			} catch (StreamNotFoundException $e) {
				continue;
			}

			// upgrading to[] and cc[] without the deleted actor and follow uri
			switch ($streamDest->getSubtype()) {
				case 'to':
					if ($stream->getTo() === $actor->getId()) {
						// the post was addressed to this account alone: it is
						// gone, and there is nothing left to rewrite on it
						$this->removeStreamAndRelated($streamDest->getStreamId());

						continue 2;
					}

					$arr = array_diff(
						$stream->getToArray(),
						[$actor->getId(), $actor->getFollowers(), $actor->getFollowing()]
					);
					if (!empty(array_diff($stream->getToArray(), $arr))) {
						$stream->setToArray($arr);
						$this->streamRequest->update($stream);
					}
					break;
				case 'cc':
					$arr = array_diff(
						$stream->getCcArray(),
						[$actor->getId(), $actor->getFollowers(), $actor->getFollowing()]
					);
					if (!empty(array_diff($stream->getCcArray(), $arr))) {
						$stream->setCcArray($arr);
						$this->streamRequest->update($stream);
					}
					break;
			}
		}

		$this->streamDestRequest->deleteRelatedToActor($actor->getId());
	}

	/**
	 * The post and everything that hangs off it. StreamRequest::deleteById()
	 * takes either a uri or the prim a dest row holds, which is what this is
	 * given, and now removes the related rows itself — the name of this method
	 * was a promise its one-line body never kept.
	 */
	private function removeStreamAndRelated(string $idPrim): void {
		$this->streamRequest->deleteById($idPrim);
	}

	private function updateActor(Person $actor, ACore $activity) {
		$actor->setCreation($activity->getOriginCreationTime());

		try {
			$current = $this->cacheActorsRequest->getFromId($actor->getId());
			if ($current->getCreation() < $activity->getOriginCreationTime()) {
				$this->cacheActorsRequest->update($actor);
			}
		} catch (CacheActorDoesNotExistException $e) {
			$this->cacheActorsRequest->save($actor);
		}
	}
}
