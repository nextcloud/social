<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;

/**
 * The object of a wrapping activity, when it arrived as a bare URI.
 *
 * `object` is defined as a link *or* an embedded object, and both are used in
 * the wild: Mastodon embeds the whole thing in an `Undo`, `Accept`, `Reject`,
 * and a bare id in an `Add`/`Remove`; other implementations send an id in all
 * of them. Every wrapping interface used to return immediately on
 * `!hasObject()`, so against a peer that sends a link the activity did nothing
 * at all — an unhandled `Accept` in particular left the follow pending forever
 * with no posts ever arriving.
 *
 * The URI is looked up in what this instance already stores, in the order the
 * wrapping activities need it: a follow, then a post, then an action (a Like or
 * a pin), then an actor. Nothing is fetched over the network — the id names
 * something we were told about before, and a wrapping activity that refers to
 * something unknown here has nothing to undo or accept.
 */
class ActivityObjectResolver {
	public function __construct(
		private StreamRequest $streamRequest,
		private FollowsRequest $followsRequest,
		private ActionsRequest $actionsRequest,
		private CacheActorsRequest $cacheActorsRequest,
	) {
	}

	/**
	 * The embedded object if the activity carries one, otherwise whatever
	 * `objectId` names in the local store.
	 *
	 * @throws ItemNotFoundException when there is neither
	 */
	public function resolve(ACore $activity): ACore {
		if ($activity->hasObject()) {
			return $activity->getObject();
		}

		$objectId = $activity->getObjectId();
		if ($objectId === '') {
			throw new ItemNotFoundException('activity carries no object');
		}

		$item = $this->fromStore($objectId);
		$item->setParent($activity);

		return $item;
	}

	/**
	 * @throws ItemNotFoundException
	 */
	private function fromStore(string $objectId): ACore {
		try {
			return $this->followsRequest->getById($objectId);
		} catch (FollowNotFoundException $e) {
		}

		try {
			return $this->streamRequest->getStreamById($objectId);
		} catch (StreamNotFoundException $e) {
		}

		try {
			return $this->actionsRequest->getById($objectId);
		} catch (ActionDoesNotExistException $e) {
		}

		try {
			return $this->cacheActorsRequest->getFromId($objectId);
		} catch (CacheActorDoesNotExistException $e) {
		}

		throw new ItemNotFoundException('unknown object: ' . $objectId);
	}
}
