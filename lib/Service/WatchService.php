<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Db\WatchRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Where somebody stopped watching, and what they were in the middle of.
 *
 * PeerTube's `WatchAction`, and the thing that makes a long video usable at
 * all: a two-hour talk watched in three sittings is three sittings of finding
 * the place again. It is a fact about a **reader** — never federated, never
 * shown to anybody else, and never counted into anything — which is also what
 * keeps it cheap: one row per (post, viewer), moved rather than appended.
 *
 * Neither the videos somebody barely started nor the ones they finished are
 * offered back. A "continue watching" row that hands back a video somebody
 * watched to the end is a row nobody presses twice.
 */
class WatchService {
	public function __construct(
		private WatchRequest $watchRequest,
		private StreamRequest $streamRequest,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Remembers where somebody got to.
	 *
	 * Nothing here may fail a request: a player reporting its position is not
	 * something worth an error over, and a bookmark that was not written is a
	 * video that starts at the beginning.
	 */
	public function remember(Stream $post, ?Person $viewer, int $position, int $duration): void {
		if ($viewer === null || $post->getId() === '' || $position < 0) {
			return;
		}

		try {
			// past the end is the end, and a duration nobody stated is one
			// nothing can be measured against
			$duration = max(0, $duration);
			if ($duration > 0) {
				$position = min($position, $duration);

				// watched to the end is not a place to come back to: the
				// bookmark is dropped rather than left pointing at the credits
				if ($position >= (int)floor((float)$duration * WatchRequest::FINISHED_RATIO)) {
					$this->watchRequest->forget($post->getId(), $viewer->getId());

					return;
				}
			}

			$this->watchRequest->remember($post->getId(), $viewer->getId(), $position, $duration);
		} catch (Throwable $e) {
			$this->logger->debug('could not remember a watch position', [
				'post' => $post->getId(), 'exception' => $e->getMessage(),
			]);
		}
	}

	/** Where this person got to in this video, in whole seconds. */
	public function positionOf(Stream $post, ?Person $viewer): int {
		if ($viewer === null || $post->getId() === '') {
			return 0;
		}

		try {
			return $this->watchRequest->positionOf($post->getId(), $viewer->getId());
		} catch (Throwable $e) {
			return 0;
		}
	}

	/**
	 * The videos this person was in the middle of, newest first.
	 *
	 * @return Stream[]
	 */
	public function unfinished(Person $viewer, int $limit = 20): array {
		$posts = [];

		foreach ($this->watchRequest->unfinished($viewer->getId(), $limit) as $prim) {
			try {
				$post = $this->streamRequest->getStream($prim);
				$post->setExportFormat(ACore::FORMAT_LOCAL);
				$posts[] = $post;
			} catch (Throwable $e) {
				// a video that has been deleted since: the bookmark outlives
				// it for a while and is simply not offered
			}
		}

		return $posts;
	}

	/** Takes one off the list, for somebody who does not want it offered. */
	public function forget(Stream $post, Person $viewer): bool {
		return $this->watchRequest->forget($post->getId(), $viewer->getId());
	}
}
