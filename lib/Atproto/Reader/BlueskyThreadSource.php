<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Publisher\PostRefs;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\Thread\ThreadSource;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A conversation on Bluesky, for a post that is there — read from there, or
 * written here and published: the thread as the AppView has it
 * (`getPostThread`), each post this server does not hold stored the way any
 * Bluesky post is (`PostStore`), parents first, so every reply's parent is
 * there before it.
 */
class BlueskyThreadSource implements ThreadSource {
	/** how deep the replies, and how high the parents, are asked for */
	private const DEPTH = 10;
	private const PARENT_HEIGHT = 20;
	/** Keep a badly branched conversation from turning one job into an unbounded crawl. */
	private const MAX_CONTINUATION_READS = 20;
	private const THREAD_VIEW = 'app.bsky.feed.defs#threadViewPost';
	private const NOT_FOUND_VIEW = 'app.bsky.feed.defs#notFoundPost';
	private const BLOCKED_VIEW = 'app.bsky.feed.defs#blockedPost';
	private bool $deepRepliesPending = false;

	public function __construct(
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private PostRefs $refs,
		private PostStore $store,
		private LabelerService $labelers,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function supports(Stream $post): bool {
		return $this->config->isEnabled() && $this->refs->strongRef($post->getId()) !== null;
	}

	#[\Override]
	public function fill(Stream $post, int $budget): int {
		return $this->fillWithTimeout($post, $budget, null);
	}

	/** Short bounded read for a thread currently being opened; background fills retain the normal timeout. */
	public function fillVisible(Stream $post, int $budget): int {
		return $this->fillWithTimeout($post, $budget, AppViewClient::INTERACTIVE_TIMEOUT);
	}

	/** Whether the last interactive read reached Bluesky's reply-depth limit. */
	public function hasDeepRepliesPending(): bool {
		return $this->deepRepliesPending;
	}

	private function fillWithTimeout(Stream $post, int $budget, ?int $timeout): int {
		$this->deepRepliesPending = false;
		$uri = $this->refs->strongRef($post->getId())['uri'] ?? '';
		if ($uri === '' || $budget <= 0) {
			return 0;
		}
		try {
			$params = ['uri' => $uri, 'depth' => self::DEPTH, 'parentHeight' => self::PARENT_HEIGHT];
			$headers = ['atproto-accept-labelers' => $this->labelers->acceptHeader()];
			$answer = $timeout === null
				? $this->appView->query('app.bsky.feed.getPostThread', $params, $headers)
				: $this->appView->query('app.bsky.feed.getPostThread', $params, $headers, $timeout);
			$thread = $answer['thread'] ?? null;
			if (!is_array($thread) || !in_array($thread['$type'] ?? null, [self::THREAD_VIEW, self::NOT_FOUND_VIEW, self::BLOCKED_VIEW], true)) {
				throw new \UnexpectedValueException('Bluesky thread response has no recognized thread view');
			}
		} catch (Throwable $e) {
			$this->logger->info('Bluesky thread not read', ['uri' => $uri, 'exception' => $e]);

			throw $e;
		}

		$stored = $this->storeThread($thread, $budget);
		// the replies its author hid on Bluesky are hidden here too
		$this->store->rememberHiddenReplies(is_array($answer['threadgate'] ?? null) ? $answer['threadgate'] : null);

		// The public AppView caps getPostThread's reply depth at ten even when
		// a larger value is requested. A normal interactive read stays one short
		// request; the queued fill follows only branches that reached that cap,
		// so deeply nested replies arrive without making the opened page wait.
		if ($timeout === null && $stored < $budget) {
			$pending = self::continuationsOf($thread, self::DEPTH);
			$seen = [$uri => true];
			$reads = 0;
			while ($pending !== [] && $stored < $budget && $reads < self::MAX_CONTINUATION_READS) {
				$nextUri = array_shift($pending);
				if ($nextUri === '' || isset($seen[$nextUri])) {
					continue;
				}
				$seen[$nextUri] = true;
				$reads++;
				try {
					$answer = $this->appView->query('app.bsky.feed.getPostThread', [
						'uri' => $nextUri,
						'depth' => self::DEPTH,
						'parentHeight' => self::PARENT_HEIGHT,
					], $headers, AppViewClient::INTERACTIVE_TIMEOUT);
					$continued = $answer['thread'] ?? null;
					if (!is_array($continued) || ($continued['$type'] ?? null) !== self::THREAD_VIEW) {
						continue;
					}
					$stored += $this->storeThread($continued, $budget - $stored);
					$pending = array_merge($pending, self::continuationsOf($continued, self::DEPTH));
				} catch (Throwable $e) {
					// A deep branch can disappear or be unavailable independently of
					// the rest of the conversation.
					$this->logger->debug('A deep Bluesky reply branch was not read', ['uri' => $nextUri, 'exception' => $e]);
				}
			}
		} elseif ($timeout !== null) {
			$this->deepRepliesPending = self::continuationsOf($thread, self::DEPTH) !== [];
		}

		return $stored;
	}

	/** Store a thread parent-first and count only new records. */
	private function storeThread(array $thread, int $budget): int {
		$stored = 0;
		foreach (self::postsOf($thread, $budget) as $view) {
			if ($stored >= $budget) {
				break;
			}
			if ($this->store->storePost($view, false)) {
				$stored++;
			} else {
				// Feed/profile imports may already have stored the post. Its
				// AppView thread view still carries newer counts, so do not leave
				// the opened conversation showing the old likes and replies.
				$this->store->refreshKnownPostCountsFromView($view);
			}
		}

		return $stored;
	}

	/**
	 * Thread views at the AppView depth limit are read as the root of another
	 * bounded request. Replies the AppView omitted for other reasons are not
	 * retried because their node does not sit at that depth limit.
	 *
	 * @return list<string> AT URIs
	 */
	public static function continuationsOf(array $thread, int $depth): array {
		$uris = [];
		$level = [[$thread, 0]];
		while ($level !== []) {
			$next = [];
			foreach ($level as [$node, $at]) {
				if (!is_array($node) || ($node['$type'] ?? '') !== self::THREAD_VIEW) {
					continue;
				}
				if ($at >= $depth) {
					$post = is_array($node['post'] ?? null) ? $node['post'] : [];
					$uri = (string)($post['uri'] ?? '');
					if ($uri !== '' && (int)($post['replyCount'] ?? 0) > 0) {
						$uris[] = $uri;
					}
					continue;
				}
				foreach (is_array($node['replies'] ?? null) ? $node['replies'] : [] as $reply) {
					$next[] = [$reply, $at + 1];
				}
			}
			$level = $next;
		}

		return array_values(array_unique($uris));
	}

	/**
	 * The post views of a thread: the parents from the top, the post, then
	 * the replies level by level.
	 *
	 * @return list<array>
	 */
	public static function postsOf(array $thread, int $limit): array {
		$posts = [];
		for ($parent = $thread['parent'] ?? null; is_array($parent) && ($parent['$type'] ?? '') === self::THREAD_VIEW; $parent = $parent['parent'] ?? null) {
			array_unshift($posts, $parent['post'] ?? null);
		}
		if (($thread['$type'] ?? '') === self::THREAD_VIEW) {
			$posts[] = $thread['post'] ?? null;
		}
		$level = [$thread];
		while ($level !== [] && count($posts) < $limit * 2) {
			$next = [];
			foreach ($level as $node) {
				foreach (is_array($node['replies'] ?? null) ? $node['replies'] : [] as $reply) {
					if (is_array($reply) && ($reply['$type'] ?? '') === self::THREAD_VIEW) {
						$posts[] = $reply['post'] ?? null;
						$next[] = $reply;
					}
				}
			}
			$level = $next;
		}

		return array_values(array_filter($posts, 'is_array'));
	}
}
