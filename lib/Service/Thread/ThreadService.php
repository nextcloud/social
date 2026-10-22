<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Thread;

use OCA\Social\Atproto\Reader\BlueskyThreadSource;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\DurableCache;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The rest of a conversation, from wherever it lives: a reader opening a
 * post sees the replies its own server knows of, not only the ones somebody
 * here happened to follow. On opening a Bluesky post, its thread is read
 * inline; other network sources are asked in a background job
 * (`Cron\FillThread`). A post written here and published to Bluesky can be
 * read from Bluesky immediately while the Fediverse read continues.
 */
class ThreadService {
	/** the most replies one fill stores */
	public const BUDGET = 150;
	/** do not re-read the same thread on each context poll */
	private const VISIBLE_CACHE = 'social.thread.visible';
	private const VISIBLE_DONE = 'done';
	private const VISIBLE_FAILED = 'failed';
	private const VISIBLE_TTL = 10;
	// The thread view polls every five seconds while filling. Let a transient
	// AppView failure be retried on the next poll instead of waiting for the
	// background scheduler to recover the conversation.
	private const VISIBLE_RETRY_TTL = 5;
	private const VISIBLE_LOCK = 'social-visible-thread-';
	private bool $blueskyDeepRepliesPending = false;

	public function __construct(
		private StreamRequest $streams,
		private LoggerInterface $logger,
		private ILockingProvider $locks,
		private ?ContainerInterface $container = null,
		private ?DurableCache $durableCache = null,
	) {
	}

	/**
	 * @return int how many replies were stored
	 */
	public function fill(string $postId): int {
		try {
			$post = $this->streams->getStreamById($postId);
		} catch (StreamNotFoundException) {
			return 0;
		}
		$stored = 0;
		foreach ($this->sources() as $source) {
			if (!$source->supports($post)) {
				continue;
			}
			try {
				$stored += $source->fill($post, self::BUDGET - $stored);
			} catch (Throwable $e) {
				$this->logger->info('A conversation was not read', ['post' => $postId, 'source' => $source::class, 'exception' => $e]);
			}
		}

		return $stored;
	}

	/**
	 * Read Bluesky replies in the request that opened the conversation. The
	 * feed has already made the user wait for this post; leaving its replies
	 * for the next cron run can add another minute or more of stale context.
	 *
	 * @return ?bool true when read, false when unavailable or unsupported, null
	 *               when another request is already reading it
	 */
	public function fillBluesky(Stream $post): ?bool {
		$this->blueskyDeepRepliesPending = false;
		$source = $this->container?->get(BlueskyThreadSource::class);
		if (!$source instanceof BlueskyThreadSource || !$source->supports($post)) {
			return false;
		}
		$key = md5($post->getId());
		$state = $this->visibleState($key);
		if ($state !== null) {
			return $state === self::VISIBLE_DONE;
		}

		$lock = self::VISIBLE_LOCK . md5($post->getId());
		if ($this->locks->isLocked($lock, ILockingProvider::LOCK_EXCLUSIVE)) {
			return null;
		}
		try {
			$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return null;
		}
		try {
			// A request may have completed between the first cache check and
			// taking the per-thread lock.
			$state = $this->visibleState($key);
			if ($state !== null) {
				return $state === self::VISIBLE_DONE;
			}
			$source->fillVisible($post, self::BUDGET);
			$this->blueskyDeepRepliesPending = $source->hasDeepRepliesPending();
			$this->rememberVisibleRead($key, self::VISIBLE_DONE, self::VISIBLE_TTL);
		} catch (Throwable $e) {
			$this->logger->info('Bluesky conversation not read during display', ['post' => $post->getId(), 'exception' => $e]);
			$this->rememberVisibleRead($key, self::VISIBLE_FAILED, self::VISIBLE_RETRY_TTL);

			return false;
		} finally {
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}

		return true;
	}

	/** Whether the last successful visible read needs its depth-limited tail filled. */
	public function hasPendingBlueskyReplies(): bool {
		return $this->blueskyDeepRepliesPending;
	}

	private function visibleState(string $key): ?string {
		try {
			$state = $this->durableCache?->getShared(self::VISIBLE_CACHE, $key);

			return is_string($state) ? $state : null;
		} catch (Throwable $e) {
			$this->logger->debug('Bluesky thread freshness not read', ['exception' => $e]);

			return null;
		}
	}

	private function rememberVisibleRead(string $key, string $state, int $ttl): void {
		try {
			$this->durableCache?->setShared(self::VISIBLE_CACHE, $key, $state, $ttl);
		} catch (Throwable $e) {
			// A cache write is only an optimization; a successful thread read
			// remains successful if the cache is temporarily unavailable.
			$this->logger->debug('Bluesky thread freshness not saved', ['exception' => $e]);
		}
	}

	/**
	 * @return list<ThreadSource>
	 */
	private function sources(): array {
		$sources = [];
		foreach ([ActivityPubThreadSource::class, BlueskyThreadSource::class] as $class) {
			$source = $this->container?->get($class);
			if ($source instanceof ThreadSource) {
				$sources[] = $source;
			}
		}

		return $sources;
	}
}
