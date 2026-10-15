<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Counts;

use DateTime;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Stores the totals a post's own network states for it, whichever network
 * that is.
 *
 * The origin's total and this instance's own interactions are kept apart the
 * way `LikeInterface` and `AnnounceInterface` keep them: what the origin said
 * minus what is counted here, so that adding this instance's likes back lands
 * on the origin's number and an unlike moves it down rather than leaving it
 * where it was. Nothing else is touched: no notification, no action.
 */
class CountWriter {
	/** each counter a network states, and the `details` key holding its half */
	private const REMOTE = [
		Details::LIKES => Details::REMOTE_LIKES,
		Details::BOOSTS => Details::REMOTE_BOOSTS,
		Details::REPLIES => Details::REMOTE_REPLIES,
		Details::QUOTES => Details::REMOTE_QUOTES,
	];

	/**
	 * How long a post its own server says is not there is left alone, in
	 * seconds: past the window the cron's pass asks in
	 * (`RemoteCountService::PUBLISHED_WINDOW`), so the pass never asks
	 * again, and a reader who opens it asks once a month at most.
	 */
	public const GONE_FOR = 30 * 86400;

	public function __construct(
		private StreamRequest $streams,
		private ActionsRequest $actions,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param ?int $likes the origin's total, null where it stated none
	 * @param array<string, array<string, mixed>> $merge `details` blocks whose
	 *                                                   keys are merged into what the post holds
	 * @param ?int $quotes the origin's total of posts quoting it, null where it stated none
	 * @return bool whether the counts were stored: the post is still here and
	 *              its `details` were written
	 */
	public function write(string $postId, ?int $likes, ?int $boosts, ?int $replies, array $merge = [], ?int $quotes = null): bool {
		try {
			// read again rather than trusted from the caller: a like that
			// arrived while the network was being asked is not this to lose
			$post = $this->streams->getStreamById($postId);
		} catch (StreamNotFoundException) {
			return false;
		}

		$stated = [Details::LIKES => $likes, Details::BOOSTS => $boosts, Details::REPLIES => $replies, Details::QUOTES => $quotes];
		$local = [
			Details::LIKES => $this->actions->countActions($postId, Like::TYPE),
			Details::BOOSTS => $this->actions->countActions($postId, Announce::TYPE),
			Details::REPLIES => $this->streams->countRepliesTo($postId),
			Details::QUOTES => $this->streams->countQuotesOf($postId),
		];

		// only the keys this writes, onto the blob as it is stored when it
		// writes: a mention or a refused quote stored meanwhile stays
		$details = $this->streams->changeDetails($postId, function (array $details) use ($post, $stated, $local, $merge): array {
			foreach (self::REMOTE as $total => $remote) {
				$details = $this->applyCount($details, $post, $total, $remote, $stated[$total], $local[$total]);
			}
			foreach ($merge as $key => $values) {
				$details[$key] = array_merge(is_array($details[$key] ?? null) ? $details[$key] : [], $values);
			}

			return $details;
		});

		return $this->stored($post, $details);
	}

	/**
	 * Stores the totals another network states for one of this instance's
	 * own posts.
	 *
	 * Its totals take in part of what is held here — what came from that
	 * network, and what local accounts also wrote there — and none of the
	 * rest. So the half kept is only what it states beyond that part, and
	 * the totals add everything held here to it: a post whose every
	 * interaction arrived here shows what it showed before. A count the
	 * network does not state keeps the half already stored.
	 *
	 * @param array<string, ?int> $stated counter => the network's total, null where it stated none
	 * @param array<string, int> $alsoThere counter => how much of what is held here the network counts too
	 * @return bool whether the counts were stored: the post is still here and
	 *              its `details` were written
	 */
	public function writeBeyond(string $postId, array $stated, array $alsoThere): bool {
		try {
			$post = $this->streams->getStreamById($postId);
		} catch (StreamNotFoundException) {
			return false;
		}

		$details = $this->streams->changeDetails($postId, static function (array $details) use ($stated, $alsoThere): array {
			foreach (self::REMOTE as $total => $remote) {
				$count = $stated[$total] ?? null;
				if ($count !== null) {
					$details[$remote] = max($count - ($alsoThere[$total] ?? 0), 0);
				}
			}

			return $details;
		});

		return $this->stored($post, $details);
	}

	/**
	 * After the origin's halves were written: the post is stamped as asked,
	 * and its totals recounted from the halves as stored — the columns add
	 * what is counted here to them, in the statement that writes them.
	 *
	 * @param ?array<array-key, mixed> $details what `changeDetails()` stored, null for nothing
	 */
	private function stored(Stream $post, ?array $details): bool {
		$this->stamp($post->getId());
		if ($details === null) {
			return false;
		}

		foreach (self::REMOTE as $remote) {
			if (array_key_exists($remote, $details) && is_numeric($details[$remote])) {
				$post->setDetailInt($remote, (int)$details[$remote]);
			}
		}
		$this->streams->recount($post, ...array_keys(self::REMOTE));

		return true;
	}

	/** Stamps a post as asked, leaving its counts as they are. */
	public function stamp(string $postId): void {
		$this->streams->markCountsRefreshed($postId, $this->now());
	}

	/**
	 * Stamps a post its own server says is not there as asked `GONE_FOR`
	 * from now, leaving its counts as they are.
	 */
	public function stampGone(string $postId): void {
		$this->streams->markCountsRefreshed($postId, $this->now()->modify('+' . self::GONE_FOR . ' seconds'));
	}

	/**
	 * One of the counts, written as its two halves into `details` as stored.
	 *
	 * Where the origin states no total, the half already stored is kept — the
	 * number is only as old as the last answer that did say something — and
	 * for a post that has never had one, the total on the row less what is
	 * held here, which is how a reply count written before the origin's half
	 * was stored survives being read again.
	 *
	 * @param array<array-key, mixed> $details
	 * @return array<array-key, mixed>
	 */
	private function applyCount(array $details, Stream $post, string $total, string $remote, ?int $stated, int $local): array {
		if ($stated !== null) {
			$origin = max($stated - $local, 0);
		} elseif (array_key_exists($remote, $details)) {
			$origin = is_numeric($details[$remote]) ? (int)$details[$remote] : 0;
		} else {
			$origin = max($post->getDetailInt($total) - $local, 0);
		}

		$details[$remote] = $origin;
		$details[$total] = $origin + $local;

		return $details;
	}

	private function now(): DateTime {
		return (new DateTime())->setTimestamp($this->time->getTime());
	}
}
