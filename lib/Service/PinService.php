<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;

/**
 * Pinned posts: the handful of own posts an account keeps at the top of its
 * profile. A pin is a row in the actions table (`Pin`), like a Like or a poll
 * vote, so no schema change is needed; pins are never federated as activities
 * of their own — remote servers read them from the actor's `featured`
 * collection, the same way Mastodon publishes them.
 *
 * Only the author can pin, so `pinned` is a property of the post rather than
 * of the viewer.
 */
class PinService {
	public const TYPE = 'Pin';

	/** Mastodon's own limit; keeps the featured collection small enough to embed. */
	public const MAX_PINS = 5;

	public function __construct(
		private StreamRequest $streamRequest,
		private ActionsRequest $actionsRequest,
	) {
	}

	/**
	 * @throws StreamNotFoundException when the post is unknown
	 * @throws InvalidActionException when the post cannot be pinned
	 */
	public function pin(Person $actor, int $nid): Stream {
		$post = $this->ownPost($actor, $nid);

		if ($this->isPinned($actor->getId(), $post->getId())) {
			return $post->setPinned(true);
		}

		if (count($this->getPinnedIds($actor->getId())) >= self::MAX_PINS) {
			throw new InvalidActionException(
				'you cannot pin more than ' . self::MAX_PINS . ' posts'
			);
		}

		$pin = new Like();
		$pin->setType(self::TYPE);
		// the actions table is keyed by the row id, so the id has to name the
		// actor as well: two actors pinning the same post must not collide
		$pin->setId($post->getId() . '#pin/' . md5($actor->getId()));
		$pin->setActorId($actor->getId());
		$pin->setObjectId($post->getId());
		$this->actionsRequest->save($pin);

		return $post->setPinned(true);
	}

	/**
	 * @throws StreamNotFoundException when the post is unknown
	 * @throws InvalidActionException when the post is not the actor's own
	 */
	public function unpin(Person $actor, int $nid): Stream {
		$post = $this->ownPost($actor, $nid);
		$this->actionsRequest->deleteAction($actor->getId(), $post->getId(), self::TYPE);

		return $post->setPinned(false);
	}

	public function isPinned(string $actorId, string $objectId): bool {
		try {
			$this->actionsRequest->getAction($actorId, $objectId, self::TYPE);

			return true;
		} catch (ActionDoesNotExistException $e) {
			return false;
		}
	}

	/**
	 * The pinned post ids of an account, newest pin first.
	 *
	 * @return string[]
	 */
	public function getPinnedIds(string $actorId): array {
		return array_map(
			static fn (ACore $pin): string => $pin->getObjectId(),
			$this->actionsRequest->getActionsByActor($actorId, self::TYPE)
		);
	}

	/**
	 * The pinned posts of an account, newest pin first. Posts the viewer
	 * cannot see, or that are gone, are skipped.
	 *
	 * @return Stream[]
	 */
	public function getPinnedPosts(string $actorId, ?Person $viewer = null): array {
		if ($viewer !== null) {
			$this->streamRequest->setViewer($viewer);
		}

		$posts = [];
		foreach ($this->getPinnedIds($actorId) as $id) {
			try {
				$post = $this->streamRequest->getStreamById($id, $viewer !== null, ACore::FORMAT_LOCAL);
			} catch (StreamNotFoundException $e) {
				continue;
			}
			$posts[] = $post->setPinned(true);
		}

		return $posts;
	}

	/**
	 * Flags the pinned ones among posts that all belong to $actorId — one
	 * query for the whole page instead of one per post.
	 *
	 * @param Stream[] $posts
	 */
	public function markPinned(array $posts, string $actorId): void {
		if ($posts === []) {
			return;
		}

		$pinned = $this->getPinnedIds($actorId);
		if ($pinned === []) {
			return;
		}

		foreach ($posts as $post) {
			if (in_array($post->getId(), $pinned, true)) {
				$post->setPinned(true);
			}
		}
	}

	/**
	 * @throws StreamNotFoundException
	 * @throws InvalidActionException
	 */
	private function ownPost(Person $actor, int $nid): Stream {
		$post = $this->streamRequest->getStreamByNid($nid);

		if (!$post->isLocal() || $post->getAttributedTo() !== $actor->getId()) {
			throw new InvalidActionException('you can only pin your own posts');
		}

		return $post;
	}
}
