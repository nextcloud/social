<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;

class MoveInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	private ActionsRequest $actionsRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private CacheDocumentsRequest $cacheDocumentsRequest;
	private FollowsRequest $followsRequest;
	private StreamRequest $streamRequest;
	private StreamDestRequest $streamDestRequest;
	private CacheActorService $cacheActorService;

	public function __construct(
		ActionsRequest $actionsRequest,
		CacheActorsRequest $cacheActorsRequest,
		CacheDocumentsRequest $cacheDocumentsRequest,
		FollowsRequest $followsRequest,
		StreamRequest $streamRequest,
		StreamDestRequest $streamDestRequest,
		CacheActorService $cacheActorService,
	) {
		$this->actionsRequest = $actionsRequest;
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->cacheDocumentsRequest = $cacheDocumentsRequest;
		$this->streamRequest = $streamRequest;
		$this->streamDestRequest = $streamDestRequest;
		$this->followsRequest = $followsRequest;
		$this->cacheActorService = $cacheActorService;
	}


	/**
	 * @throws InvalidOriginException
	 */
	public function processIncomingRequest(ACore $item): void {
		$item->checkOrigin($item->getId());
		$item->checkOrigin($item->getObjectId());
		$item->checkOrigin($item->getActorId());

		try {
			// cache only: an actor this instance has never seen has nothing to move
			$old = $this->cacheActorsRequest->getFromId($item->getActorId());
		} catch (CacheActorDoesNotExistException $e) {
			return;
		}

		// Refresh the target so the alsoKnownAs list is the one its server
		// publishes right now, not a stale cached copy.
		$new = $this->cacheActorService->getFromId($item->getTarget(), true);

		// The signature only proves the Move comes from the old account's
		// server. Without the back-reference, anyone could re-point another
		// actor's followers and posts at an account they control.
		if (!in_array($old->getId(), $new->getAlsoKnownAs(), true)) {
			throw new InvalidOriginException(
				'MoveInterface - target ' . $new->getId()
				. ' does not list ' . $old->getId() . ' in alsoKnownAs'
			);
		}

		$this->moveAccount($old, $new);
	}


	public function moveAccount(Person $actor, Person $target): void {
		$this->actionsRequest->moveAccount($actor->getId(), $target->getId());
		$this->cacheDocumentsRequest->moveAccount($actor->getId(), $target->getId());

		$this->followsRequest->moveAccountFollowers($actor->getId(), $target);
		$this->followsRequest->moveAccountFollowing($actor->getId(), $target);

		$this->updateStreamFromActor($actor, $target);
	}


	/**
	 * @param Person $actor
	 */
	private function updateStreamFromActor(Person $actor, Person $new): void {
		// first, we delete all post generate by actor
		$this->streamRequest->updateAuthor($actor->getId(), $new->getId());

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

			// upgrading to[] and cc[] based on old actorId and followId with new uri
			$changed = false;
			switch ($streamDest->getSubtype()) {
				case 'to':
					if ($stream->getTo() === $actor->getId()) {
						$stream->setTo($new->getId());
						$changed = true;
					}

					foreach (
						[
							$actor->getId() => $new->getId(),
							$actor->getFollowers() => $new->getFollowers(),
							$actor->getFollowing() => $new->getFollowing()
						] as $itemId => $newId
					) {
						$arr = $stream->getToArray();
						if (in_array($itemId, $arr)) {
							$stream->setToArray(array_unique(array_merge(array_diff($arr, [$itemId]), [$newId])));
							$changed = true;
						}
					}
					break;

				case 'cc':
					$changed = false;
					foreach (
						[
							$actor->getId() => $new->getId(),
							$actor->getFollowers() => $new->getFollowers(),
							$actor->getFollowing() => $new->getFollowing()
						] as $itemId => $newId
					) {
						$arr = $stream->getCcArray();
						if (in_array($itemId, $arr)) {
							$stream->setCcArray(array_unique(array_merge(array_diff($arr, [$itemId]), [$newId])));
							$changed = true;
						}
					}
					break;
			}

			if ($changed) {
				$this->streamRequest->update($stream);
			}
		}

		$this->streamDestRequest->moveActor($actor->getId(), $new->getId());
		$this->streamDestRequest->moveActor($actor->getFollowing(), $new->getFollowing());
		$this->streamDestRequest->moveActor($actor->getFollowers(), $new->getFollowers());
	}
}
