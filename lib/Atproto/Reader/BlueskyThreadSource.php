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
	/** @var list<string> direct replies recovered by the V2 read which have replies of their own */
	private array $missingReplyContinuations = [];

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
		$this->missingReplyContinuations = [];
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
		if (self::hasMissingDirectReplies($thread) && $stored < $budget) {
			$knownReplies = [];
			foreach (is_array($thread['replies'] ?? null) ? $thread['replies'] : [] as $reply) {
				$replyUri = (string)($reply['post']['uri'] ?? '');
				if ($replyUri !== '') {
					$knownReplies[] = $replyUri;
				}
			}
			$stored += $this->fillMissingDirectReplies($uri, $headers, $timeout, $budget - $stored, $knownReplies);
		}

		// The public AppView caps getPostThread's reply depth at ten even when
		// a larger value is requested. A normal interactive read stays one short
		// request; the queued fill follows only branches that reached that cap,
		// so deeply nested replies arrive without making the opened page wait.
		if ($timeout === null && $stored < $budget) {
			$pending = array_merge(self::continuationsOf($thread, self::DEPTH), $this->missingReplyContinuations);
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
			$this->deepRepliesPending = self::continuationsOf($thread, self::DEPTH) !== [] || $this->missingReplyContinuations !== [];
		}

		return $stored;
	}

	/** The stable thread endpoint can omit direct replies; the first-party AppView's V2 view can name the rest. */
	private function fillMissingDirectReplies(string $uri, array $headers, ?int $timeout, int $budget, array $knownReplies): int {
		try {
			$params = [
				'anchor' => $uri,
				'above' => 'false',
				'below' => 1,
				'branchingFactor' => 0,
				'sort' => 'oldest',
			];
			$answer = $timeout === null
				? $this->appView->query('app.bsky.unspecced.getPostThreadV2', $params, $headers)
				: $this->appView->query('app.bsky.unspecced.getPostThreadV2', $params, $headers, $timeout);
			$items = $answer['thread'] ?? null;
			if (!is_array($items)) {
				return 0;
			}
		} catch (Throwable $e) {
			// The V2 thread view is still unspecced and is absent on some AppViews.
			$this->logger->debug('Bluesky V2 thread view not available; keeping the standard thread response', ['uri' => $uri, 'exception' => $e]);

			return 0;
		}

		$views = [];
		foreach ($items as $item) {
			if (!is_array($item) || (int)($item['depth'] ?? -1) < 0) {
				continue;
			}
			$value = is_array($item['value'] ?? null) ? $item['value'] : [];
			if (($value['$type'] ?? '') !== 'app.bsky.unspecced.defs#threadItemPost' || !is_array($value['post'] ?? null)) {
				continue;
			}
			$post = $value['post'];
			$views[] = [(int)$item['depth'], $post];
			if ((int)($item['depth'] ?? -1) === 1 && (int)($post['replyCount'] ?? 0) > 0 && is_string($post['uri'] ?? null)
				&& !in_array($post['uri'], $knownReplies, true)) {
				$this->missingReplyContinuations[] = $post['uri'];
			}
		}
		$this->missingReplyContinuations = array_values(array_unique($this->missingReplyContinuations));
		usort($views, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
		$stored = 0;
		foreach ($views as [, $view]) {
			if ($stored >= $budget) {
				break;
			}
			if ($this->store->storePost($view, false)) {
				$stored++;
			} else {
				$this->store->refreshKnownPostCountsFromView($view);
			}
		}
		$this->store->rememberHiddenReplies(is_array($answer['threadgate'] ?? null) ? $answer['threadgate'] : null);

		return $stored;
	}

	/** Whether the stable response's root has fewer visible reply nodes than its current reply count. */
	private static function hasMissingDirectReplies(array $thread): bool {
		if (($thread['$type'] ?? '') !== self::THREAD_VIEW) {
			return false;
		}
		$post = is_array($thread['post'] ?? null) ? $thread['post'] : [];
		$replyCount = $post['replyCount'] ?? null;
		$replies = is_array($thread['replies'] ?? null) ? $thread['replies'] : [];

		return is_int($replyCount) && $replyCount > count($replies);
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
