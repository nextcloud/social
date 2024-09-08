<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Actor;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\RequestQueueRequest;
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
		RequestQueueRequest $requestQueueRequest,
		StreamRequest $streamRequest,
		StreamDestRequest $streamDestRequest,
		ActorService $actorService,
		ConfigService $configService
	) {
		$this->actionsRequest = $actionsRequest;
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->cacheDocumentsRequest = $cacheDocumentsRequest;
		$this->followsRequest = $followsRequest;
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
						$this->removeStreamAndRelated($streamDest->getStreamId());
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


	// get stream's relative and remove everything
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
