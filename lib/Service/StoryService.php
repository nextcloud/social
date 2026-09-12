<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StoriesRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Story;
use OCP\IURLGenerator;

/**
 * Stories: one picture that stops existing after a day.
 *
 * Who may see one is the same question as who may see a followers-only post,
 * and it is answered the same way: the owner, and the accounts that follow
 * them. There is no public story. That is Pixelfed's rule and it is the one
 * people assume when they post something that disappears -- a story visible to
 * the open internet, cached by whatever crawled it, is exactly what the feature
 * promises not to be.
 */
class StoryService {
	public function __construct(
		private StoriesRequest $storiesRequest,
		private DocumentService $documentService,
		private FollowService $followService,
		private CacheActorService $cacheActorService,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * Posts one of the viewer's own uploads as a story.
	 *
	 * @throws InvalidResourceException when the upload is unknown or there are
	 *                                  already too many live
	 */
	public function add(Person $owner, int $mediaNid, string $caption, int $duration): Story {
		$documents = $this->documentService->getMediaFromArray(
			[(string)$mediaNid], $owner->getPreferredUsername()
		);
		if (count($documents) !== 1) {
			throw new InvalidResourceException('unknown media');
		}

		if ($this->storiesRequest->countLiveByActor($owner->getId()) >= Story::MAX_PER_ACTOR) {
			throw new InvalidResourceException(
				'this account already has ' . Story::MAX_PER_ACTOR . ' live stories'
			);
		}

		$story = new Story();
		$story->setOwnerId($owner->getId())
			->setDocumentId($documents[0]->getId())
			->setCaption($caption)
			->setDuration($duration);

		return $this->hydrate($this->storiesRequest->save($story), $owner);
	}

	/** @throws ItemNotFoundException */
	public function delete(Person $owner, int $id): void {
		// reads it first so that somebody else's id is a 404 rather than a
		// delete that quietly matched nothing
		$story = $this->storiesRequest->getLiveById($id);
		if ($story->getOwnerId() !== $owner->getId()) {
			throw new ItemNotFoundException('unknown story');
		}

		$this->storiesRequest->delete($owner->getId(), $id);
	}

	/**
	 * The carousel: the viewer's own live stories first, then those of the
	 * accounts they follow.
	 *
	 * @return Story[]
	 */
	public function carousel(Person $viewer): array {
		$following = [];
		foreach ($this->followService->getFollowing($viewer) as $follow) {
			$following[] = $follow->getObjectId();
		}

		$stories = array_merge(
			$this->storiesRequest->getLiveByActor($viewer->getId()),
			$this->storiesRequest->getLiveByActors($following)
		);

		return $this->hydrateAll($stories, $viewer);
	}

	/**
	 * The live stories of one account, as the viewer may see them.
	 *
	 * @return Story[]
	 *
	 * @throws ItemNotFoundException when the viewer may not see them
	 */
	public function forAccount(Person $viewer, Person $owner): array {
		if ($viewer->getId() !== $owner->getId()) {
			$links = $this->followService->getLinksBetweenPersons($viewer, $owner);
			if ($links['following'] !== true) {
				// not "forbidden": whether an account has a story up is itself
				// something only its followers are told
				throw new ItemNotFoundException('no stories');
			}
		}

		return $this->hydrateAll($this->storiesRequest->getLiveByActor($owner->getId()), $viewer);
	}

	/**
	 * Marks a story seen by the viewer.
	 *
	 * @throws ItemNotFoundException when it is unknown, expired, or not the
	 *                               viewer's to see
	 */
	public function markSeen(Person $viewer, int $id): Story {
		$story = $this->storiesRequest->getLiveById($id);

		if ($story->getOwnerId() !== $viewer->getId()) {
			$owner = new Person();
			$owner->setId($story->getOwnerId());
			$links = $this->followService->getLinksBetweenPersons($viewer, $owner);
			if ($links['following'] !== true) {
				throw new ItemNotFoundException('unknown story');
			}
		}

		$this->storiesRequest->markSeen($id, $viewer->getId());

		return $this->hydrate($story, $viewer);
	}

	/** Deletes what has expired. Called from cron. */
	public function purgeExpired(int $limit = 500): int {
		return $this->storiesRequest->deleteExpired($limit);
	}

	/** Everything an account owns, for a deletion or a suspension. */
	public function forgetActor(string $actorId): void {
		$this->storiesRequest->deleteRelatedId($actorId);
	}

	/**
	 * @param Story[] $stories
	 *
	 * @return Story[]
	 */
	private function hydrateAll(array $stories, Person $viewer): array {
		if ($stories === []) {
			return [];
		}

		$ids = array_map(static fn (Story $story): int => $story->getId(), $stories);
		// one query for the whole carousel, not one per story
		$seen = $this->storiesRequest->seenAmong($ids, $viewer->getId());

		foreach ($stories as $story) {
			$story->setSeen(in_array($story->getId(), $seen, true));
			$this->attachMedia($story);
			$this->attachAuthor($story);
			// Only the poster is told how many people watched. Set *and*
			// cleared, rather than only set: leaving whatever the object
			// happened to carry would make the guarantee depend on the story
			// having been freshly read, which is not something a caller can see.
			$story->setViewCount(
				($story->getOwnerId() === $viewer->getId())
					? $this->storiesRequest->countViews($story->getId())
					: 0
			);
		}

		return $stories;
	}

	private function hydrate(Story $story, Person $viewer): Story {
		return $this->hydrateAll([$story], $viewer)[0];
	}

	private function attachMedia(Story $story): void {
		try {
			$document = $this->documentService->getDocumentById($story->getDocumentId());
			$story->setMedia($document->convertToMediaAttachment($this->urlGenerator));
		} catch (\Exception $e) {
			// a story whose picture is gone is a story with no picture; the row
			// is about to expire anyway and an empty frame beats a 500
			$story->setMedia(null);
		}
	}

	private function attachAuthor(Story $story): void {
		try {
			$story->setAuthor($this->cacheActorService->getFromId($story->getOwnerId()));
		} catch (\Exception $e) {
			$story->setAuthor(null);
		}
	}
}
