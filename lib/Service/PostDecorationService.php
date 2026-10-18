<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\MediaTagsRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;

/**
 * What a client is shown on a post that is not part of the stored post: its
 * link preview, its place, its reaction bar, the people named in its
 * pictures and, on a video posted here, how many times it was watched.
 *
 * Every route that serves posts to a client calls `decorate()`, whether it
 * serves a timeline page, a thread or one status, so a post looks the same
 * wherever it is opened. One query per kind for the whole list, never one per
 * post. None of it is part of the wire object, so nothing that serves posts to
 * another server calls this.
 *
 * Depends on nothing that depends on `StreamService` or `MediaTagService`,
 * which both call it.
 */
class PostDecorationService {
	public function __construct(
		private LinkPreviewService $linkPreviewService,
		private PlaceService $placeService,
		private ReactionSummaryService $reactionSummaryService,
		private MediaTagsRequest $mediaTagsRequest,
		private CacheActorService $cacheActorService,
		private RemoteFetchQueue $remoteFetchQueue,
		private ?VideoViewService $videoViewService = null,
	) {
	}

	/**
	 * @param Stream[] $posts
	 * @param string $viewerId the reader's actor id, which marks their own
	 *                         reactions as `me`; '' for an anonymous reader
	 */
	public function decorate(array $posts, string $viewerId = ''): void {
		if ($posts === []) {
			return;
		}

		$this->linkPreviewService->attachCards($posts);
		$this->placeService->attachPlaces($posts);
		$this->reactionSummaryService->attachReactions($posts, $viewerId);
		$this->attachTaggedPeople($posts);
		$this->videoViewService?->attach($posts);
	}

	/**
	 * The people named in each post's pictures.
	 *
	 * On the wire the names are `Mention` tags, which is where a peer looks
	 * for them; this is the list a client draws under the picture.
	 *
	 * The people are read from the actor cache only, in one query: a page is
	 * being built, and fetching an uncached person from their server here
	 * held the whole timeline for that server's timeout. Somebody not cached
	 * is left out of this page and fetched in the background
	 * (`RemoteFetchQueue`), so the next page that shows the picture names them.
	 *
	 * @param Stream[] $posts
	 */
	private function attachTaggedPeople(array $posts): void {
		$nids = [];
		foreach ($posts as $post) {
			if ($post->getNid() > 0) {
				$nids[] = (string)$post->getNid();
			}
		}

		$tags = $this->mediaTagsRequest->forStreams($nids);
		if ($tags === []) {
			return;
		}

		$wanted = [];
		foreach ($posts as $post) {
			foreach ($tags[$post->getNid()] ?? [] as $actorId) {
				$wanted[$actorId] = $actorId;
			}
		}

		$people = $this->cacheActorService->getCachedFromIds(array_values($wanted));
		foreach ($people as $person) {
			$person->setExportFormat(ACore::FORMAT_LOCAL);
		}
		$this->remoteFetchQueue->resolveActors(array_values(array_diff_key($wanted, $people)));

		foreach ($posts as $post) {
			$named = [];
			foreach ($tags[$post->getNid()] ?? [] as $actorId) {
				if (isset($people[$actorId])) {
					$named[] = $people[$actorId];
				}
			}
			$post->setTaggedPeople($named);
		}
	}
}
