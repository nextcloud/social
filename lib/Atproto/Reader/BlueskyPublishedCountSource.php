<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Service\Counts\CountSource;
use OCA\Social\Service\Counts\CountWriter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The counts of one of this instance's own posts that is also a record on
 * Bluesky, as the AppView has them now: `getPosts`, 25 records a call.
 *
 * A like, repost, reply or quote on Bluesky is a record in somebody else's
 * repository, and this instance only hears of it through the author's
 * notifications (`NotificationPoller`); one the poll missed is never
 * counted. The AppView's totals take in what did arrive, and the likes and
 * boosts of local accounts that are on Bluesky too, so only what they state
 * beyond that is kept (`CountWriter::writeBeyond()`,
 * `AtprotoRepoRequest::countHeldOnBluesky()`). An author whose presence on
 * Bluesky is switched off is not asked about; the post keeps what it has.
 */
class BlueskyPublishedCountSource implements CountSource {
	public function __construct(
		private Publisher $publisher,
		private AtprotoRepoRequest $records,
		private IdentityService $identities,
		private CacheActorsRequest $actors,
		private AppViewClient $appView,
		private CountWriter $writer,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function supports(Stream $post): bool {
		return $post->isLocal() && $this->publisher->goesToBluesky($post);
	}

	#[\Override]
	public function refresh(array $posts, ?int $timeout = null): int {
		$answered = 0;
		foreach (array_chunk($posts, BlueskyCountSource::BATCH) as $chunk) {
			$uris = [];
			foreach ($chunk as $post) {
				$uri = $this->uriOf($post);
				if ($uri === '') {
					if ($timeout === null) {
						$this->writer->stamp($post->getId());
					}
				} else {
					$uris[$post->getId()] = $uri;
				}
			}
			if ($uris === []) {
				continue;
			}

			$views = $this->views(array_values($uris), $timeout);
			foreach ($uris as $id => $uri) {
				$view = $views[$uri] ?? null;
				if ($view === null) {
					// not indexed yet, or not answered: asked again one interval on
					if ($timeout === null) {
						$this->writer->stamp($id);
					}
					continue;
				}
				$stated = [
					Details::LIKES => BlueskyCountSource::count($view, 'likeCount'),
					Details::BOOSTS => BlueskyCountSource::count($view, 'repostCount'),
					Details::REPLIES => BlueskyCountSource::count($view, 'replyCount'),
					Details::QUOTES => BlueskyCountSource::count($view, 'quoteCount'),
				];
				if (!array_filter($stated, static fn (?int $count): bool => $count !== null)) {
					if ($timeout === null) {
						$this->writer->stamp($id);
					}
					continue;
				}
				if ($this->writer->writeBeyond($id, $stated, $this->records->countHeldOnBluesky($id))) {
					$answered++;
				}
			}
		}

		return $answered;
	}

	/**
	 * The `at://` URI of the post's record, '' when it has none or its author
	 * is not on Bluesky now.
	 */
	private function uriOf(Stream $post): string {
		foreach ($this->records->getRecordsByLocalId($post->getId()) as $record) {
			if ($record->collection !== RecordMapper::POST) {
				continue;
			}
			$identity = $this->authorOf($post);

			return ($identity !== null && $identity->did === $record->did) ? $record->uri() : '';
		}

		return '';
	}

	private function authorOf(Stream $post): ?Identity {
		$author = $post->getActor();
		if ($author === null) {
			try {
				$author = $this->actors->getFromId($post->getAttributedTo());
			} catch (CacheActorDoesNotExistException) {
				return null;
			}
		}

		return $this->identities->activeForActor($author);
	}

	/**
	 * @param list<string> $uris
	 * @return array<string, array> the AppView's views, by URI; empty when it did not answer
	 */
	private function views(array $uris, ?int $timeout = null): array {
		try {
			$answer = $timeout === null
				? $this->appView->query('app.bsky.feed.getPosts', ['uris' => $uris])
				: $this->appView->query('app.bsky.feed.getPosts', ['uris' => $uris], [], $timeout);
		} catch (Throwable $e) {
			$this->logger->notice('Counts of posts on Bluesky not read', ['exception' => $e]);

			return [];
		}
		$views = [];
		foreach (is_array($answer['posts'] ?? null) ? $answer['posts'] : [] as $view) {
			if (is_array($view) && is_string($view['uri'] ?? null)) {
				$views[$view['uri']] = $view;
			}
		}

		return $views;
	}
}
