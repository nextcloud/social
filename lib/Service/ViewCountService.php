<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StreamViewsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
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
				$this->streamViewsRequest->seen($post->getId(), $viewer->getId());
			}

			$this->attach($post, $viewer);
		} catch (Throwable $e) {
			$this->logger->debug('could not count a view', [
				'post' => $post->getId(), 'exception' => $e->getMessage(),
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
