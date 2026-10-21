<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Model\Watch;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Exceptions\AppViewNotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Reads the feeds of the Bluesky authors somebody here follows (D12): the
 * watches due, each asked the public AppView for its next page, every
 * post newer than the last read stored, and the next read set by backoff —
 * soon after a page with posts, doubling up to six hours after empty ones,
 * reset by the next post. One pass is bounded twice: by how many watches
 * it visits and by the instance-wide ceiling on AppView requests. An
 * account the AppView refuses as deactivated, taken down or gone, three
 * reads in a row, is hidden or purged here (`AccountStatus`); one it
 * serves again is restored.
 */
class FeedPoller {
	private ?string $acceptLabelers = null;

	/** how soon a watch is read again after a page with posts */
	public const INTERVAL = 120;
	public const MAX_BACKOFF = 6 * 3600;
	/** watches visited per pass */
	public const BATCH = 50;
	/** posts asked for per watch and pass, continuing a backlog on the next pass */
	public const PAGE = 50;
	/** consecutive refusals naming an account's status before it is acted on */
	public const STATUS_AFTER = 3;

	public function __construct(
		private AtprotoConfig $config,
		private AtprotoWatchRequest $watches,
		private AppViewClient $appView,
		private PostStore $store,
		private BlueskyActorService $actors,
		private Blocklist $blocklist,
		private LabelerService $labelers,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private ?AccountStatus $status = null,
	) {
	}

	/**
	 * @param int $deadline the wall clock past which no further watch is read, 0 for none
	 * @return array{watches: int, stored: int, requests: int}
	 */
	public function poll(int $limit = self::BATCH, int $deadline = 0): array {
		$result = ['watches' => 0, 'stored' => 0, 'requests' => 0];
		if (!$this->config->isEnabled()) {
			return $result;
		}
		$ceiling = $this->config->syncCeiling();
		foreach ($this->watches->getDue($this->time->getTime(), $limit) as $watch) {
			if ($result['requests'] >= $ceiling || ($deadline > 0 && $this->time->getTime() >= $deadline)) {
				break;
			}
			$result['watches']++;
			$result['requests']++;
			$result['stored'] += $this->pollWatch($watch);
		}

		return $result;
	}

	/**
	 * One watch: one page towards its last committed timestamp.
	 *
	 * @return int how many rows were stored
	 */
	public function pollWatch(Watch $watch): int {
		$now = $this->time->getTime();
		$actor = $this->actors->cached($watch->did);
		if ($this->blocklist->isBlockedDid($watch->did) || ($actor !== null && $this->blocklist->isBlockedActor($actor))) {
			$this->watches->remove($watch->did);

			return 0;
		}
		// an account its labels limit is left alone; one Bluesky switched off
		// is asked about, so it is restored when it comes back
		if ($actor !== null && BlueskyActorService::isLimited($actor) && BlueskyActorService::status($actor) === '') {
			$this->watches->synced($watch->did, $watch->cursor, $now, $now + self::MAX_BACKOFF);

			return 0;
		}
		try {
			$checkpoint = new PollCursor($watch->cursor);
			$feed = $this->appView->query('app.bsky.feed.getAuthorFeed', ['actor' => $watch->did, 'filter' => 'posts_with_replies', 'limit' => self::PAGE] + $checkpoint->parameters(), $this->labelHeaders());
			if (!is_array($feed['feed'] ?? null)) {
				throw new UnexpectedValueException('Bluesky author feed has no feed array');
			}
			if ($actor !== null && BlueskyActorService::status($actor) !== '') {
				$this->status?->restore($watch->did);
			}

			$stored = 0;
			$newest = $checkpoint->newest;
			$handle = '';
			$reached = false;
			foreach ($feed['feed'] as $item) {
				if (!is_array($item) || ($item['reason']['$type'] ?? '') === 'app.bsky.feed.defs#reasonPin') {
					continue;
				}
				$seen = self::timeOf($item);
				if ($checkpoint->since !== '' && $seen !== '' && $seen < $checkpoint->since) {
					$reached = true;
					break;
				}
				$newest = max($newest, $seen);
				if ($handle === '' && ($item['post']['author']['did'] ?? '') === $watch->did) {
					$handle = strtolower((string)($item['post']['author']['handle'] ?? ''));
				}
				$stored += $this->store->storeFeedItem($item);
			}
			$next = $checkpoint->after($newest, $feed['cursor'] ?? '', $reached, true);
		} catch (AppViewNotFoundException $e) {
			$status = AccountStatus::ERRORS[$e->error] ?? '';
			if ($status === '') {
				// an account that is gone, deactivated or blocking: the watch
				// stays, tried again later, in case it comes back
				$this->watches->failed($watch->did, $e->getMessage(), $now + self::MAX_BACKOFF);

				return 0;
			}
			// the AppView names the status: acted on once it has said so a
			// few reads in a row, with the ordinary backoff between them
			$this->watches->failed($watch->did, $e->getMessage(), $now + $this->failureBackoff($watch));
			if ($watch->failures + 1 >= self::STATUS_AFTER) {
				$this->status?->apply($watch->did, false, $status);
			}

			return 0;
		} catch (Throwable $e) {
			$this->logger->notice('Bluesky feed not read', ['did' => $watch->did, 'exception' => $e]);
			$this->watches->failed($watch->did, $e->getMessage(), $now + $this->failureBackoff($watch));

			return 0;
		}
		if ($handle !== '' && $handle !== $watch->handle) {
			$this->watches->setHandle($watch->did, $handle);
		}
		$this->watches->synced($watch->did, $next, $now, $now + $this->nextInterval($watch, $stored > 0 || $next !== $newest));

		return $stored;
	}

	/**
	 * The moment a feed item was indexed: the repost's for a repost, the
	 * post's otherwise — the order the feed is in.
	 */
	public static function timeOf(array $item): string {
		$reason = $item['reason'] ?? null;
		if (is_array($reason) && is_string($reason['indexedAt'] ?? null)) {
			return $reason['indexedAt'];
		}

		return (string)($item['post']['indexedAt'] ?? '');
	}

	/** How long after a failed read the next is: doubling with the failures, up to the maximum. */
	private function failureBackoff(Watch $watch): int {
		return min(self::MAX_BACKOFF, self::INTERVAL * (1 << min(10, $watch->failures + 1)));
	}

	private function nextInterval(Watch $watch, bool $hadPosts): int {
		if ($hadPosts || $watch->lastSync === 0) {
			return self::INTERVAL;
		}
		$last = max(self::INTERVAL, $watch->nextSync - $watch->lastSync);

		return min(self::MAX_BACKOFF, $last * 2);
	}

	/**
	 * The labelers the AppView is asked to label for: Bluesky's and the ones
	 * people here subscribe to, once per pass.
	 *
	 * @return array<string, string>
	 */
	private function labelHeaders(): array {
		return ['atproto-accept-labelers' => $this->acceptLabelers ??= $this->labelers->acceptHeader()];
	}
}
