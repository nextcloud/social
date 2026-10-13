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
	 * @return bool whether the post is still stored here
	 */
	public function write(string $postId, ?int $likes, ?int $boosts, ?int $replies, array $merge = [], ?int $quotes = null): bool {
		try {
			// read again rather than trusted from the caller: a like that
			// arrived while the network was being asked is not this to lose
			$post = $this->streams->getStreamById($postId);
		} catch (StreamNotFoundException) {
			return false;
		}

		$this->applyCount($post, Details::LIKES, Details::REMOTE_LIKES, $likes, $this->actions->countActions($postId, Like::TYPE));
		$this->applyCount($post, Details::BOOSTS, Details::REMOTE_BOOSTS, $boosts, $this->actions->countActions($postId, Announce::TYPE));
		$this->applyCount($post, Details::REPLIES, Details::REMOTE_REPLIES, $replies, $this->streams->countRepliesTo($postId));
		$this->applyCount($post, Details::QUOTES, Details::REMOTE_QUOTES, $quotes, $this->streams->countQuotesOf($postId));
		foreach ($merge as $key => $values) {
			$post->setDetailArray($key, array_merge($post->getDetails($key), $values));
		}

		// the origin's halves are `details` keys, the totals are columns that
		// add what is counted here to them, in the statement that writes them
		$this->streams->updateDetails($post, $this->now());
		$this->streams->recount($post, Details::LIKES, Details::BOOSTS, Details::REPLIES, Details::QUOTES);

		return true;
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
	 * @return bool whether the post is still stored here
	 */
	public function writeBeyond(string $postId, array $stated, array $alsoThere): bool {
		try {
			$post = $this->streams->getStreamById($postId);
		} catch (StreamNotFoundException) {
			return false;
		}

		foreach (self::REMOTE as $total => $remote) {
			$count = $stated[$total] ?? null;
			if ($count !== null) {
				$post->setDetailInt($remote, max($count - ($alsoThere[$total] ?? 0), 0));
			}
		}

		$this->streams->updateDetails($post, $this->now());
		$this->streams->recount($post, ...array_keys(self::REMOTE));

		return true;
	}

	/** Stamps a post as asked, leaving its counts as they are. */
	public function stamp(string $postId): void {
		$this->streams->markCountsRefreshed($postId, $this->now());
	}

	/**
	 * One of the three counts, written as its two halves.
	 *
	 * Where the origin states no total, the half already stored is kept — the
	 * number is only as old as the last answer that did say something — and
	 * for a post that has never had one, the total on the row less what is
	 * held here, which is how a reply count written before the origin's half
	 * was stored survives being read again.
	 */
	private function applyCount(Stream $post, string $total, string $remote, ?int $stated, int $local): void {
		if ($stated !== null) {
			$origin = max($stated - $local, 0);
		} elseif (array_key_exists($remote, $post->getDetailsAll())) {
			$origin = $post->getDetailInt($remote);
		} else {
			$origin = max($post->getDetailInt($total) - $local, 0);
		}

		$post->setDetailInt($remote, $origin);
		$post->setDetailInt($total, $origin + $local);
	}

	private function now(): DateTime {
		return (new DateTime())->setTimestamp($this->time->getTime());
	}
}
