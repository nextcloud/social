<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;

/**
 * What an account has done here, and what came back.
 *
 * Every number is counted from this instance's own rows at the moment it is
 * asked for; nothing is stored, and nothing is precomputed by a cron. That
 * keeps the page honest — it cannot show a total that a deletion has already
 * made false — and it is what bounds the work: the walk stops at
 * `MAX_POSTS`, and the answer says how many posts it actually looked at so the
 * page can say so too.
 *
 * The engagement figures are per *post*, taken from the `details` each post
 * carries. That column is a JSON blob, which is why this is a walk in PHP
 * rather than a `SUM()`: the three databases this app supports do not agree on
 * how to reach inside one, and a portable aggregate would mean a column that
 * has to be kept in step with the blob.
 *
 * A remote like is included, because `details.likes` is the local count plus
 * whatever the origin reported. What no instance can know is the part of the
 * network that never told anybody, so these are a floor rather than a total —
 * which is true of every Fediverse statistic and is worth the page saying.
 */
class StatisticsService {
	/**
	 * How far back the walk goes.
	 *
	 * An account with more posts than this gets the most recent ones, and the
	 * page says which. The alternative is a page whose cost grows without
	 * limit for the accounts that use the app most.
	 */
	public const MAX_POSTS = 2000;

	/** How many posts one query takes. */
	private const PAGE = 100;

	/** How many of the best posts are named. */
	private const TOP_POSTS = 3;

	/** How many hashtags are named. */
	private const TOP_HASHTAGS = 6;

	/** How many months of history the two histograms cover. */
	private const MONTHS = 12;

	public function __construct(
		private StreamRequest $streamRequest,
		private FollowsRequest $followsRequest,
		private AccountService $accountService,
	) {
	}

	/**
	 * @return array<string, mixed> the whole page, in one answer
	 */
	public function forAccount(Person $actor): array {
		$posts = [
			'total' => 0,
			'originals' => 0,
			'replies' => 0,
			'boosts' => 0,
			'with_media' => 0,
			'sensitive' => 0,
		];
		$engagement = ['likes' => 0, 'boosts' => 0, 'replies' => 0];
		$visibility = [
			Stream::TYPE_PUBLIC => 0,
			Stream::TYPE_UNLISTED => 0,
			Stream::TYPE_FOLLOWERS => 0,
			Stream::TYPE_DIRECT => 0,
		];
		$byMonth = $this->emptyMonths();
		$byHour = array_fill(0, 24, 0);
		$hashtags = [];
		$best = [];
		$first = 0;
		$last = 0;

		foreach ($this->posts($actor) as $post) {
			$posts['total']++;

			$published = $post->getPublishedTime();
			if ($published > 0) {
				$first = ($first === 0) ? $published : min($first, $published);
				$last = max($last, $published);
				$month = gmdate('Y-m', $published);
				if (array_key_exists($month, $byMonth)) {
					$byMonth[$month]++;
				}
				$byHour[(int)gmdate('G', $published)]++;
			}

			if ($post->getType() === 'Announce' || $post->getSubType() === 'Announce') {
				// a boost is something the account did, not something it wrote,
				// so it is counted and then left out of everything below: its
				// likes belong to whoever wrote it
				$posts['boosts']++;
				continue;
			}

			if ($post->getInReplyTo() !== '') {
				$posts['replies']++;
			} else {
				$posts['originals']++;
			}

			if ($post->getAttachments() !== []) {
				$posts['with_media']++;
			}
			if ($post->isSensitive()) {
				$posts['sensitive']++;
			}

			$scope = $post->getVisibility();
			if (array_key_exists($scope, $visibility)) {
				$visibility[$scope]++;
			}

			foreach ($post->getHashtags() as $hashtag) {
				$hashtag = strtolower((string)$hashtag);
				if ($hashtag === '') {
					continue;
				}
				$hashtags[$hashtag] = ($hashtags[$hashtag] ?? 0) + 1;
			}

			$likes = $post->getDetailInt('likes');
			$boosts = $post->getDetailInt('boosts');
			$replies = $post->getDetailInt('replies');
			$engagement['likes'] += $likes;
			$engagement['boosts'] += $boosts;
			$engagement['replies'] += $replies;

			$best[] = [
				'id' => (string)$post->getNid(),
				'url' => $post->getId(),
				'published_at' => $this->asDate($published),
				'excerpt' => $this->excerpt($post),
				'likes' => $likes,
				'boosts' => $boosts,
				'replies' => $replies,
				'score' => $likes + $boosts,
			];
		}

		arsort($hashtags);
		usort($best, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

		$written = $posts['originals'] + $posts['replies'];

		return [
			'account' => [
				'acct' => $actor->getPreferredUsername(),
				'created_at' => $this->asDate($actor->getCreation()),
				'followers' => $this->followsRequest->countFollowers($actor->getId()),
				'following' => $this->followsRequest->countFollowing($actor->getId()),
			],
			'posts' => $posts,
			'engagement' => array_merge($engagement, [
				// per post the account wrote, which is what the reader is
				// asking about — dividing by the boosts as well would make an
				// account that boosts a lot look like one nobody answers
				'likes_per_post' => $this->ratio($engagement['likes'], $written),
				'boosts_per_post' => $this->ratio($engagement['boosts'], $written),
				'replies_per_post' => $this->ratio($engagement['replies'], $written),
			]),
			'visibility' => $visibility,
			'by_month' => $byMonth,
			'by_hour' => $byHour,
			'hashtags' => $this->named($hashtags, self::TOP_HASHTAGS),
			'best' => array_slice($best, 0, self::TOP_POSTS),
			'window' => [
				'counted' => $posts['total'],
				'capped' => $posts['total'] >= self::MAX_POSTS,
				'max' => self::MAX_POSTS,
				'first_at' => $this->asDate($first),
				'last_at' => $this->asDate($last),
			],
		];
	}

	/**
	 * The account's own posts, newest first, up to the cap.
	 *
	 * Keyset by nid rather than an offset, which is how every other paged read
	 * in this app walks a timeline: an offset would skip a post whenever one
	 * was deleted while the walk was running.
	 *
	 * @return iterable<Stream>
	 */
	private function posts(Person $actor): iterable {
		$this->streamRequest->setViewer($actor);
		$maxId = 0;
		$seen = 0;

		while ($seen < self::MAX_POSTS) {
			$options = new ProbeOptions();
			$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
				->setProbe(ProbeOptions::ACCOUNT)
				->setAccountId($actor->getId())
				->setLimit(min(self::PAGE, self::MAX_POSTS - $seen));
			if ($maxId > 0) {
				$options->setMaxId($maxId);
			}

			$page = $this->streamRequest->getTimeline($options);
			if ($page === []) {
				return;
			}

			foreach ($page as $post) {
				$seen++;
				yield $post;
			}

			$last = end($page);
			$nid = ($last === false) ? 0 : $last->getNid();
			if ($nid <= 0 || ($maxId > 0 && $nid >= $maxId)) {
				// a row that cannot be paged on: stop rather than ask for the
				// same page for ever
				return;
			}

			$maxId = $nid;
		}
	}

	/**
	 * The last twelve months, in order, each at zero.
	 *
	 * Built rather than derived from the posts so that a month nothing was
	 * posted in is a gap in the chart instead of a missing column.
	 *
	 * @return array<string, int>
	 */
	private function emptyMonths(): array {
		$months = [];
		for ($back = self::MONTHS - 1; $back >= 0; $back--) {
			$months[gmdate('Y-m', strtotime('-' . $back . ' months', time()))] = 0;
		}

		return $months;
	}

	/**
	 * @param array<string, int> $counted
	 * @return array<int, array{name: string, count: int}>
	 */
	private function named(array $counted, int $limit): array {
		$named = [];
		foreach (array_slice($counted, 0, $limit, true) as $name => $count) {
			$named[] = ['name' => $name, 'count' => $count];
		}

		return $named;
	}

	/** One decimal, and never a division by nothing. */
	private function ratio(int $total, int $over): float {
		return ($over < 1) ? 0.0 : round($total / $over, 1);
	}

	/** An ISO date, or '' where there is no date to give. */
	private function asDate(int $timestamp): string {
		return ($timestamp > 0) ? gmdate('Y-m-d\TH:i:s', $timestamp) . '.000Z' : '';
	}

	/**
	 * Enough of a post to recognise it by.
	 *
	 * Tags are stripped rather than rendered: this goes into a list of links,
	 * and a post's own markup has no business laying that list out.
	 */
	private function excerpt(Stream $post): string {
		$text = trim(html_entity_decode(strip_tags($post->getContent()), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$text = (string)preg_replace('/\s+/u', ' ', $text);

		if (mb_strlen($text) <= 90) {
			return $text;
		}

		return mb_substr($text, 0, 89) . '…';
	}
}
