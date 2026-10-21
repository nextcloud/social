<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Counts;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Reader\BlueskyCountSource;
use OCA\Social\Atproto\Reader\BlueskyPublishedCountSource;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\RemoteFetchQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The likes, boosts and replies of a post that lives elsewhere, kept current
 * wherever it lives.
 *
 * A post from another server arrives with the counts it had at that moment,
 * and nothing tells this server when they move. So the posts somebody looks
 * at are asked about again: a status, a conversation or a page of a timeline
 * hands its remote posts to `seen()`, which only queues — the page does no
 * network I/O — a background read (`Cron\RefreshCounts`) of the ones whose
 * counts are older than their interval: a quarter of an hour for a post less
 * than a day old, six hours after that. Each network the post may be on is
 * a `CountSource`; what it answers is stored in the post's row
 * (`CountWriter`), so every reader sees it, and `counts_at` says when it
 * was asked. The cron's pass over posts nobody looked at
 * (`RemoteCountService`) goes through `refreshPosts()` too.
 *
 * A post of this instance's own is counted here, except where it is also on
 * a network that keeps counts this instance may not have heard of: a post
 * published to Bluesky is asked about the same way
 * (`BlueskyPublishedCountSource`).
 */
class CountService {
	/** how old a post is while it is asked about often */
	public const YOUNG = 86400;
	public const YOUNG_INTERVAL = 900;
	public const OLD_INTERVAL = 21600;
	/** Live reader refresh cadence, separate from the slower background scan. */
	public const VISIBLE_INTERVAL = 5;
	private const VISIBLE_RETRY_INTERVAL = 15;
	private const VISIBLE_CACHE = 'social.counts.visible';
	private const VISIBLE_PENDING = 'pending';
	private const VISIBLE_DONE = 'done';
	private const VISIBLE_LOCK = 'social-visible-count-';

	public function __construct(
		private StreamRequest $streams,
		private CountWriter $writer,
		private RemoteFetchQueue $queue,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private ILockingProvider $locks,
		private DurableCache $durableCache,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * A page showed these posts: the ones whose counts are due (`isDue()`) are
	 * queued to be asked about (`RemoteFetchQueue::refreshCounts()`, which
	 * caps a call). A boost stands for the post it boosts.
	 *
	 * @param iterable<mixed> $posts
	 * @return bool whether any post still needs its remote counts refreshed
	 */
	public function seen(iterable $posts): bool {
		$now = $this->time->getTime();
		$due = [];
		foreach ($posts as $post) {
			if ($post instanceof Stream && $post->getType() === Announce::TYPE && $post->hasObject()) {
				$post = $post->getObject();
			}
			if ($post instanceof Stream && $this->isDue($post, $now)) {
				$due[$post->getId()] = self::interval($post, $now);
			}
		}

		if ($due === []) {
			return false;
		}

		// The queue deduplicates work already requested by another page or
		// request. A post is still filling until the worker stamps its new counts,
		// so callers must keep the refresh signal while the row remains due.
		$this->queue->refreshCounts($due);

		return true;
	}

	/**
	 * Refresh counts from the post's network while it is being opened. A queued
	 * job can take a full scheduler interval to run, which is too late for a
	 * post the reader is looking at now.
	 */
	public function refreshVisibleIfDue(Stream $post, bool $force = false): bool {
		$now = $this->time->getTime();
		if ($post->getType() === Announce::TYPE) {
			$object = $post->getObject();
			if ($object instanceof Stream) {
				$post = $object;
			}
		}

		$countsAt = $post->getCountsAt();
		if (!$force && $countsAt !== null && $now - $countsAt < self::VISIBLE_INTERVAL) {
			return false;
		}
		$visible = $this->visibleState($post->getId());
		if ($visible !== null && (!$force || $visible === self::VISIBLE_PENDING)) {
			return $visible === self::VISIBLE_PENDING;
		}

		$lock = self::VISIBLE_LOCK . md5($post->getId());
		if ($this->locks->isLocked($lock, ILockingProvider::LOCK_EXCLUSIVE)) {
			return true;
		}
		try {
			$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return true;
		}

		try {
			try {
				$post = $this->streams->getStreamById($post->getId());
			} catch (StreamNotFoundException) {
				return false;
			}
			$countsAt = $post->getCountsAt();
			if (!$force && $countsAt !== null && $now - $countsAt < self::VISIBLE_INTERVAL) {
				return false;
			}
			$visible = $this->visibleState($post->getId());
			if ($visible !== null && (!$force || $visible === self::VISIBLE_PENDING)) {
				return $visible === self::VISIBLE_PENDING;
			}

			foreach ($this->sources() as $source) {
				if (!$source->supports($post)) {
					continue;
				}
				try {
					$answered = $source->refresh([$post], AppViewClient::INTERACTIVE_TIMEOUT);
					if ($answered === 0) {
						$this->queueVisibleRetry($post);
						$this->setVisibleState($post->getId(), self::VISIBLE_PENDING, self::VISIBLE_RETRY_INTERVAL);
					} else {
						$this->setVisibleState($post->getId(), self::VISIBLE_DONE, self::VISIBLE_INTERVAL);
					}
				} catch (Throwable $e) {
					$this->logger->info('Post counts not read during display', ['post' => $post->getId(), 'exception' => $e]);
					$this->queueVisibleRetry($post);
					$this->setVisibleState($post->getId(), self::VISIBLE_PENDING, self::VISIBLE_RETRY_INTERVAL);
				}
				return true;
			}

			return false;
		} finally {
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** Whether a visible refresh of this post is still running or queued. */
	public function visibleRefreshPending(string $postId): bool {
		return $this->visibleState($postId) === self::VISIBLE_PENDING
			|| $this->locks->isLocked(self::VISIBLE_LOCK . md5($postId), ILockingProvider::LOCK_EXCLUSIVE);
	}

	/** A forced live read may fail even when the ordinary count interval is fresh. */
	private function queueVisibleRetry(Stream $post): void {
		$this->queue->refreshCounts([$post->getId() => self::VISIBLE_RETRY_INTERVAL], true);
	}

	/** How long a post's counts stand before it is asked about again, in seconds. */
	public static function interval(Stream $post, int $now): int {
		return ($now - $post->getPublishedTime() < self::YOUNG) ? self::YOUNG_INTERVAL : self::OLD_INTERVAL;
	}

	/**
	 * Whether a post is one whose counts are kept elsewhere and were last
	 * asked about longer ago than its interval. A direct message has no
	 * counts to speak of. A post of this instance's own is counted here,
	 * unless it is on another network too that keeps counts of its own.
	 */
	public function isDue(Stream $post, int $now): bool {
		if ($post->getId() === '' || $post->getVisibility() === Stream::TYPE_DIRECT) {
			return false;
		}
		if ($post->isLocal() && !$this->isSupported($post)) {
			return false;
		}
		$at = $post->getCountsAt();

		return $at === null || $now - $at >= self::interval($post, $now);
	}

	/**
	 * The background read (`Cron\RefreshCounts`) of posts that were seen:
	 * the ones still due — a page seen twice, or the cron, may have asked
	 * already.
	 *
	 * @param string[] $postIds
	 * @return array{asked: int, answered: int}
	 */
	public function refresh(array $postIds, bool $force = false): array {
		$now = $this->time->getTime();
		$posts = [];
		foreach (array_unique($postIds) as $id) {
			try {
				$post = $this->streams->getStreamById($id);
			} catch (StreamNotFoundException) {
				continue;
			}
			if ($force || $this->isDue($post, $now)) {
				$posts[] = $post;
			}
		}

		return $this->refreshPosts($posts);
	}

	/**
	 * Asks each post's network about it now, whatever was last asked. A post
	 * no network answers for is stamped all the same, so it is not handed
	 * over again before its interval is out.
	 *
	 * @param Stream[] $posts
	 * @return array{asked: int, answered: int}
	 */
	public function refreshPosts(array $posts): array {
		$left = [];
		foreach ($posts as $post) {
			$left[$post->getId()] = $post;
		}
		$asked = count($left);
		$answered = 0;
		foreach ($this->sources() as $source) {
			$mine = array_filter($left, static fn (Stream $post): bool => $source->supports($post));
			if ($mine === []) {
				continue;
			}
			$left = array_diff_key($left, $mine);
			try {
				$answered += $source->refresh(array_values($mine));
			} catch (Throwable $e) {
				$this->logger->info('Counts not read', ['source' => $source::class, 'exception' => $e]);
				$this->stampAll($mine);
			}
		}
		$this->stampAll($left);
		return ['asked' => $asked, 'answered' => $answered];
	}

	private function visibleState(string $postId): ?string {
		$value = $this->durableCache->getShared(self::VISIBLE_CACHE, md5($postId));

		return is_string($value) ? $value : null;
	}

	private function setVisibleState(string $postId, string $state, int $ttl): void {
		$this->durableCache->setShared(self::VISIBLE_CACHE, md5($postId), $state, $ttl);
	}

	/**
	 * @param array<string, Stream> $posts
	 */
	private function stampAll(array $posts): void {
		foreach (array_keys($posts) as $id) {
			try {
				$this->writer->stamp((string)$id);
			} catch (Throwable $e) {
				$this->logger->info('Counts not stamped', ['post' => $id, 'exception' => $e]);
			}
		}
	}

	private function isSupported(Stream $post): bool {
		foreach ($this->sources() as $source) {
			if ($source->supports($post)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<CountSource>
	 */
	private function sources(): array {
		$sources = [];
		foreach ([ActivityPubCountSource::class, BlueskyCountSource::class, BlueskyPublishedCountSource::class] as $class) {
			$source = $this->container?->get($class);
			if ($source instanceof CountSource) {
				$sources[] = $source;
			}
		}

		return $sources;
	}
}
