<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Db\StreamViewsRequest;
use OCA\Social\Model\ActivityPub\Activity\View;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * How many people have opened a post.
 *
 * An author could see three likes and had no way to know whether that was
 * three out of five or three out of four hundred. A story has had a view count
 * since it was written; the thing people actually agonise over had none.
 *
 * Three rules, and each of them is what keeps the number meaning something:
 *
 *  - **Only a post's own page counts.** A post scrolled past in a timeline has
 *    not been read, and counting impressions would both make the number
 *    meaningless and write a row for every post on every page of every
 *    timeline.
 *  - **Only a signed-in account.** An anonymous reader cannot be counted
 *    without keeping something about them that this app deliberately does not
 *    keep; the number is "accounts here who opened it", and the label says so.
 *  - **Only the author is told.** How many people read a post is the author's
 *    business. It is not on anybody else's copy, which is also why it is not
 *    federated: a count that arrived from another server would be a number
 *    about that server's readers, added to this one's, meaning neither.
 */
class ViewCountService {
	public function __construct(
		private StreamViewsRequest $streamViewsRequest,
		private StreamRequest $streamRequest,
		private CacheActorService $cacheActorService,
		private SignatureService $signatureService,
		private ActivityService $activityService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Remembers that this viewer opened this post, and puts the count on it
	 * when the viewer is its author.
	 *
	 * Nothing here may fail a read: somebody opening a post gets the post,
	 * whatever the counter does.
	 */
	public function seen(Stream $post, ?Person $viewer): void {
		if ($viewer === null || $post->getId() === '') {
			return;
		}

		try {
			if ($viewer->getId() !== $post->getAttributedTo()) {
				// `seen()` is idempotent on (post, viewer), so a second play is
				// not a second view — and the receipt only goes out the first
				// time, which is what stops this being a way to inflate
				// somebody else's counter from here
				if ($this->streamViewsRequest->seen($post->getId(), $viewer->getId())) {
					$this->sendView($post, $viewer);
				}
			}

			$this->attach($post, $viewer);
		} catch (Throwable $e) {
			$this->logger->debug('could not count a view', [
				'post' => $post->getId(), 'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Tells the server that holds a video that somebody here has watched it.
	 *
	 * PeerTube counts views and its authors care about the number, so an
	 * instance whose readers watch without ever saying so is a freeloader on
	 * everybody else's counters. One `View` per (post, person), because that is
	 * what is recorded here — a second play by the same person is not a second
	 * view, and sending one per press of play would be a way to inflate
	 * somebody's number from here.
	 *
	 * A failure is not a failure of the watching: the view is recorded either
	 * way, and a receipt that could not be sent is a receipt that did not
	 * arrive rather than a view that did not happen.
	 */
	private function sendView(Stream $post, Person $viewer): void {
		if ($post->isLocal() || $post->getAttributedTo() === $viewer->getId()) {
			return;
		}

		// only a video: every other kind of post is read rather than watched,
		// and nothing on the network counts those
		if ($post->getType() !== 'Video' && $post->getSubType() !== 'Video') {
			return;
		}

		try {
			$owner = $this->cacheActorService->getFromId($post->getAttributedTo());

			$activity = new View();
			// minted under the watching account: Pixelfed and PeerTube both
			// refuse an activity whose id is on a different host from its actor
			$activity->setId($viewer->getId() . '#views/' . md5($post->getId()));
			$activity->setActor($viewer);
			$activity->setStoryId($post->getId());
			$activity->setTo($owner->getId());
			$activity->addInstancePath(new InstancePath(
				$owner->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW
			));

			$this->signatureService->signObject($viewer, $activity);
			$this->activityService->request($activity);
		} catch (Throwable $e) {
			$this->logger->debug('could not send a view receipt', [
				'post' => $post->getId(), 'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Somebody on another server watched one of our videos.
	 *
	 * PeerTube sends a `View` to the owner of a video for every watch, and this
	 * is the one place this app's view count takes a number from anywhere else.
	 * It does so on the same terms it counts a local one, which is what keeps
	 * the number meaning the same thing: **one row per (post, person)**, so it
	 * counts people rather than plays, and the person is the actor the sending
	 * server signed for. Inflating it therefore costs an actor id per view,
	 * which is the same bar a local account faces.
	 *
	 * Only our own posts: a view of somebody else's video is their server's
	 * business, and adding it to a copy we hold would be a count about their
	 * readers stored on our row.
	 */
	public function receiveView(string $postId, string $viewerId): void {
		if ($postId === '' || $viewerId === '') {
			return;
		}

		try {
			$post = $this->streamRequest->getStreamById($postId);
			if (!$post->isLocal() || $post->getAttributedTo() === $viewerId) {
				return;
			}

			$this->streamViewsRequest->seen($post->getId(), $viewerId);
		} catch (Throwable $e) {
			$this->logger->debug('could not count a view from another server', [
				'post' => $postId, 'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Puts the count on a post the viewer wrote, and on nobody else's.
	 */
	public function attach(Stream $post, ?Person $viewer): void {
		if ($viewer === null || $viewer->getId() !== $post->getAttributedTo()) {
			return;
		}

		$post->setViewCount($this->streamViewsRequest->countFor($post->getId()));
	}

	/**
	 * The same, for a page of the author's own posts, in one query.
	 *
	 * @param Stream[] $posts
	 */
	public function attachAll(array $posts, ?Person $viewer): void {
		if ($viewer === null || $posts === []) {
			return;
		}

		$own = [];
		foreach ($posts as $post) {
			if ($viewer->getId() === $post->getAttributedTo() && $post->getId() !== '') {
				$own[] = $post->getId();
			}
		}

		if ($own === []) {
			return;
		}

		try {
			$counts = $this->streamViewsRequest->countForMany($own);
		} catch (Throwable $e) {
			$this->logger->debug('could not read the view counts', ['exception' => $e->getMessage()]);

			return;
		}

		foreach ($posts as $post) {
			if ($viewer->getId() === $post->getAttributedTo()) {
				$post->setViewCount($counts[md5($post->getId())] ?? 0);
			}
		}
	}
}
