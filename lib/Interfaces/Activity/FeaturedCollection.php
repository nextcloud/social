<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use Exception;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Service\PinService;
use Psr\Log\LoggerInterface;

/**
 * A remote account pinning and unpinning its own posts.
 *
 * A pin is not federated as an activity of its own: what travels is
 * `Add`/`Remove` naming the actor's `featured` collection as `target`. Both
 * used to be no-ops here — they returned unless `object` had arrived as an
 * embedded object (Mastodon sends a bare URI), and even then delegated to
 * `NoteInterface::activity()`, which only knows Create/Delete/Update. So
 * pinned posts were outbound-only: a remote profile never showed one.
 *
 * The pin is stored the way a local pin is (a `Pin` row in the actions table),
 * so `PinService` reads local and remote pins alike.
 */
class FeaturedCollection {
	/**
	 * A remote may pin whatever it likes in its own collection, and each pin is
	 * a row we write on its say-so. Mastodon's own ceiling is five; this leaves
	 * room for peers that are more generous without letting the table be filled
	 * from outside.
	 */
	public const MAX_REMOTE_PINS = 20;

	public function __construct(
		private CacheActorsRequest $cacheActorsRequest,
		private StreamRequest $streamRequest,
		private ActionsRequest $actionsRequest,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws InvalidOriginException
	 */
	public function toggle(ACore $activity, bool $pinned): void {
		$activity->checkOrigin($activity->getActorId());

		$target = $activity->getTarget();
		if ($target === '') {
			// AS2 allows target to be absent; without one there is no way to
			// tell a pin from any other collection membership
			$this->logger->debug('a collection activity carries no target', [
				'type' => $activity->getType(), 'activity' => $activity->getId(),
			]);

			return;
		}

		try {
			$actor = $this->cacheActorsRequest->getFromId($activity->getActorId());
		} catch (CacheActorDoesNotExistException $e) {
			return;
		}

		// Add/Remove is also how featured hashtags and, on other
		// implementations, arbitrary collections are maintained. Only the
		// pinned-posts collection of the actor who sent it is ours to act on.
		if ($actor->getFeatured() === '' || $actor->getFeatured() !== $target) {
			$this->logger->debug('a collection activity targets a collection we do not track', [
				'type' => $activity->getType(), 'target' => $target, 'actor' => $actor->getId(),
			]);

			return;
		}

		$objectId = $activity->hasObject() ? $activity->getObject()->getId() : $activity->getObjectId();
		if ($objectId === '') {
			return;
		}

		try {
			$post = $this->streamRequest->getStreamById($objectId);
		} catch (StreamNotFoundException $e) {
			// nothing to show as pinned; the post will be pinned again the next
			// time the profile is read from `featured`
			$this->logger->notice('a pinned post is not known here', [
				'post' => $objectId, 'actor' => $actor->getId(),
			]);

			return;
		}

		if ($post->getAttributedTo() !== $actor->getId()) {
			$this->logger->notice('refusing to pin a post to an account that did not write it', [
				'post' => $post->getId(),
				'author' => $post->getAttributedTo(),
				'actor' => $actor->getId(),
			]);

			return;
		}

		if (!$pinned) {
			$this->actionsRequest->deleteAction($actor->getId(), $post->getId(), PinService::TYPE);

			return;
		}

		$this->pin($actor, $post->getId());
	}

	private function pin(Person $actor, string $postId): void {
		try {
			$this->actionsRequest->getAction($actor->getId(), $postId, PinService::TYPE);

			return; // already pinned
		} catch (ActionDoesNotExistException $e) {
		}

		$known = $this->actionsRequest->getActionsByActor($actor->getId(), PinService::TYPE);
		if (count($known) >= self::MAX_REMOTE_PINS) {
			$this->logger->notice('an account pins more posts than we keep', [
				'actor' => $actor->getId(), 'limit' => self::MAX_REMOTE_PINS,
			]);

			return;
		}

		$pin = new Like();
		$pin->setType(PinService::TYPE);
		// same shape as a local pin (PinService::pin): the row id has to name
		// the actor too, or two actors pinning the same post would collide
		$pin->setId($postId . '#pin/' . md5($actor->getId()));
		$pin->setActorId($actor->getId());
		$pin->setObjectId($postId);

		try {
			$this->actionsRequest->save($pin);
		} catch (Exception $e) {
			// a concurrent delivery of the same Add got there first
			$this->logger->debug('pin already stored', ['post' => $postId, 'exception' => $e]);
		}
	}
}
