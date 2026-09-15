<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\MediaTagsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Naming the people in a picture.
 *
 * A staple of every photo network there has been, and this app had nothing:
 * somebody arriving from Instagram or Pixelfed expects to tag the people in a
 * photograph, expects to be told when they are in one, and expects a "photos
 * of you" page of their own. Pixelfed calls the row a `MediaTag`.
 *
 * Three decisions worth defending:
 *
 *  - **Only the post's author may tag.** Anybody being able to write their own
 *    name onto anybody's photograph is a way to put a post in front of an
 *    audience that did not ask for it, and to make somebody's profile show
 *    pictures they have never seen.
 *  - **Anybody named may take their own name off**, and the author may too.
 *    Being in somebody else's photograph is not something to need their
 *    permission to leave; this is Pixelfed's "untag me" and it is the whole
 *    remedy the feature needs.
 *  - **A tag addresses the person.** It is written onto the post as a
 *    `Mention`, which is what makes their own server tell them — a tag that
 *    only this instance knew about would notify local accounts and silently do
 *    nothing for everybody else. The post is then re-sent as an `Update`, so
 *    the servers that already have it learn the name too.
 *
 * A tag does not widen who may *see* the post. A followers-only photograph
 * stays followers-only when somebody is named in it, and the name is simply
 * not shown to a reader who cannot see the post — because there is no such
 * reader, the post itself being out of reach.
 */
class MediaTagService {
	public function __construct(
		private MediaTagsRequest $mediaTagsRequest,
		private StreamRequest $streamRequest,
		private StreamService $streamService,
		private CacheActorService $cacheActorService,
		private ActivityService $activityService,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Names people in one of the author's own posts.
	 *
	 * The list is what the post should end up naming, not what to add: a
	 * client sends the whole list, and anybody dropped from it is untagged.
	 * That is what makes calling this twice with the same list a no-op rather
	 * than a growing pile.
	 *
	 * @param string[] $accounts handles or ids, as a client has them
	 *
	 * @return Person[] the people the post now names
	 *
	 * @throws ItemNotFoundException the post is unknown or not the author's
	 * @throws InvalidResourceException there is no picture, or too many names
	 */
	public function tag(Person $author, int $nid, array $accounts): array {
		$post = $this->ownPost($author, $nid);

		if ($post->getAttachments() === []) {
			// the feature is "who is in this picture"; on a post with none the
			// answer would be a list of people attached to nothing
			throw new InvalidResourceException('there is no picture on this post');
		}

		if (count($accounts) > MediaTagsRequest::MAX_PER_POST) {
			throw new InvalidResourceException(
				'at most ' . MediaTagsRequest::MAX_PER_POST . ' people in one post'
			);
		}

		$people = [];
		foreach ($accounts as $account) {
			$account = trim((string)$account);
			if ($account === '') {
				continue;
			}

			try {
				$person = $this->cacheActorService->resolve($account);
			} catch (Throwable $e) {
				// an account this server cannot find is left out rather than
				// failing the whole list: a client sends names as they were
				// typed, and one of them being gone is not a reason to lose
				// the rest
				$this->logger->debug('could not name an account in a post', [
					'account' => $account, 'reason' => $e->getMessage(),
				]);
				continue;
			}

			// the shape a client reads. Resolved actors come back in the
			// ActivityPub format, which has no `acct`, no `display_name` and
			// no `avatar` — everything a name under a photograph draws
			$person->setExportFormat(ACore::FORMAT_LOCAL);
			$people[$person->getId()] = $person;
		}

		$was = $this->mediaTagsRequest->forStreams([$nid])[$nid] ?? [];
		$now = array_keys($people);

		foreach (array_diff($was, $now) as $gone) {
			$this->mediaTagsRequest->untag($nid, $gone);
		}

		foreach ($people as $person) {
			if ($this->mediaTagsRequest->tag($nid, md5($post->getId()), $person->getId(), $author->getId())
				&& $person->getId() !== $author->getId()) {
				$this->notificationService->onPhotoTag($post, $author, $person);
			}
		}

		$this->republish($author, $nid, array_values($people));

		return array_values($people);
	}

	/**
	 * Takes one name off a post.
	 *
	 * The person named, or the author. Anybody else asking is told the same as
	 * somebody asking about a post that does not exist.
	 *
	 * @throws ItemNotFoundException
	 */
	public function untag(Person $viewer, int $nid, string $accountId = ''): bool {
		$accountId = ($accountId === '') ? $viewer->getId() : $accountId;

		try {
			$post = $this->streamService->getStreamByNid($nid);
		} catch (Throwable $e) {
			throw new ItemNotFoundException('unknown post');
		}

		$mine = ($accountId === $viewer->getId());
		if (!$mine && $post->getAttributedTo() !== $viewer->getId()) {
			throw new ItemNotFoundException('unknown post');
		}

		$removed = $this->mediaTagsRequest->untag($nid, $accountId);
		if ($removed && $post->getAttributedTo() === $viewer->getId()) {
			// the author taking a name off re-sends the post without it; a
			// person untagging themselves does not, because re-addressing
			// somebody else's post is not theirs to do
			$this->republish($viewer, $nid, $this->peopleIn($nid));
		}

		return $removed;
	}

	/**
	 * The posts somebody is named in, as the reader may see them.
	 *
	 * The visibility rule is not re-implemented here: the ids come out of the
	 * tag table and the posts are read the way any other post is read for this
	 * reader, so one they may not see is simply not among them.
	 *
	 * @return Stream[]
	 */
	public function photosOf(Person $viewer, string $accountId, int $limit = 20, int $maxId = 0): array {
		$nids = $this->mediaTagsRequest->streamsFor($accountId, $limit, $maxId);

		$posts = [];
		foreach ($nids as $nid) {
			try {
				$post = $this->streamRequest->getStreamByNid($nid);
			} catch (Throwable $e) {
				// gone, or not this reader's to see
				continue;
			}
			$posts[] = $post;
		}

		$this->streamService->attachTaggedPeople($posts);

		return $posts;
	}

	/** Every tag on a post, for a deletion. */
	public function forgetStream(int $nid): void {
		$this->mediaTagsRequest->deleteByStream($nid);
	}

	/** Every tag naming an account, for a deletion or a suspension. */
	public function forgetActor(string $actorId): void {
		$this->mediaTagsRequest->deleteByActor($actorId);
	}

	/**
	 * The people a post names now, resolved.
	 *
	 * @return Person[]
	 */
	private function peopleIn(int $nid): array {
		$ids = $this->mediaTagsRequest->forStreams([$nid])[$nid] ?? [];

		$people = [];
		foreach ($ids as $id) {
			$person = $this->personFor($id);
			if ($person !== null) {
				$people[] = $person;
			}
		}

		return $people;
	}

	/**
	 * Writes the names onto the post as mentions and sends it again.
	 *
	 * This is what makes a tag reach the person's own server. A failure is not
	 * a failure of the tagging — the names are stored here either way, and a
	 * post that could not be re-sent is a notification that did not arrive
	 * rather than a tag that was not made.
	 *
	 * @param Person[] $people
	 */
	private function republish(Person $author, int $nid, array $people): void {
		try {
			$post = $this->streamService->getStreamByNid($nid);
			if (!$post->isLocal()) {
				return;
			}

			foreach ($people as $person) {
				// a mention, addressed the way a mention written in the text of
				// the post would be — `cc` on anything but a direct message,
				// which is not a wider audience than the post already had
				$handle = $person->getAccount();
				if ($handle !== '') {
					$this->streamService->addRecipient($post, $post->getVisibility(), $handle);
				}
			}

			$post->setUpdated(gmdate('Y-m-d\TH:i:s\Z'));
			$this->streamService->updateStream($post);
			$this->activityService->updateActivity($author, $post);
		} catch (Throwable $e) {
			$this->logger->warning('could not send a post again after naming somebody in it', [
				'post' => $nid, 'exception' => $e,
			]);
		}
	}

	/**
	 * @throws ItemNotFoundException the post is unknown, or somebody else's
	 */
	private function ownPost(Person $author, int $nid): Stream {
		try {
			$post = $this->streamService->getStreamByNid($nid);
		} catch (Throwable $e) {
			throw new ItemNotFoundException('unknown post');
		}

		if ($post->getAttributedTo() !== $author->getId()) {
			// not "forbidden": whose post it is is not a fact to confirm to
			// somebody who is asking about one that is not theirs
			throw new ItemNotFoundException('unknown post');
		}

		return $post;
	}

	private function personFor(string $actorId): ?Person {
		try {
			$person = $this->cacheActorService->getFromId($actorId);
			$person->setExportFormat(ACore::FORMAT_LOCAL);

			return $person;
		} catch (Throwable $e) {
			// somebody this server cannot name any more is left out rather
			// than drawn as a blank
			return null;
		}
	}
}
