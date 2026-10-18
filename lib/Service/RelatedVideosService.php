<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\Options\ProbeOptions;

/**
 * What a watch page offers to play next: more from the same channel, then
 * videos that share a hashtag with this one, then videos in its category.
 *
 * Nothing here decides who may see what. Each source is a timeline the reader
 * can already ask for — the author's statuses narrowed to videos, a hashtag's
 * page narrowed to videos, the public timeline narrowed to videos — read by
 * `StreamService::getTimeline()` and put through the reader's filters by
 * `FilterService::apply()` in the context that timeline uses. So a post the
 * reader may not read, an account they blocked or muted where that timeline
 * leaves it out, and a post one of their `hide` filters matches are left out
 * here exactly as they are there, and every query is one those timelines
 * already run on their indexes.
 *
 * The channel takes at most half the list before the others have had their
 * turn, so a channel with a hundred videos does not fill it alone; what is
 * left over of the channel's comes last. The category is not a column: it is
 * matched among the newest public videos, a bounded window, rather than
 * searched for across every post.
 */
class RelatedVideosService {
	public const LIMIT = 12;
	public const MAX_LIMIT = 24;

	/** How many of the post's hashtags are asked about. */
	private const TAGS = 3;

	/** How many of the newest public videos the category is matched among. */
	private const CATEGORY_WINDOW = 40;

	public function __construct(
		private StreamService $streamService,
		private FilterService $filterService,
	) {
	}

	/**
	 * The videos to offer after this post, as statuses a client is served,
	 * the post itself never among them.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function related(Stream $post, ?Person $viewer, int $limit = self::LIMIT): array {
		$limit = min(max($limit, 1), self::MAX_LIMIT);
		$exclude = (string)$post->getNid();

		// one more than the list, since the post itself is usually among them
		$channel = $this->videos(
			(new ProbeOptions())->setProbe(ProbeOptions::ACCOUNT)->setAccountId($post->getAttributedTo()),
			$limit + 1, Filter::CONTEXT_ACCOUNT, $viewer
		);
		$share = intdiv($limit + 1, 2);

		$others = [];
		foreach (array_slice($this->hashtagsOf($post), 0, self::TAGS) as $tag) {
			$others = array_merge($others, $this->videos(
				(new ProbeOptions())->setProbe(ProbeOptions::HASHTAG)->setArgument($tag),
				$limit + 1, Filter::CONTEXT_PUBLIC, $viewer
			));
		}

		$category = $this->categoryOf($post);
		if ($category !== '') {
			$others = array_merge($others, array_filter(
				$this->videos(
					(new ProbeOptions())->setProbe(ProbeOptions::PUBLIC),
					self::CATEGORY_WINDOW, Filter::CONTEXT_PUBLIC, $viewer
				),
				static fn (array $status): bool => ($status['video_card']['category'] ?? null) === $category
			));
		}

		return $this->merge(
			[array_slice($channel, 0, $share), $others, array_slice($channel, $share)],
			$exclude, $limit
		);
	}

	/**
	 * One timeline's newest videos, as the reader is served that timeline.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function videos(ProbeOptions $options, int $limit, string $context, ?Person $viewer): array {
		$options->setFormat(ACore::FORMAT_LOCAL);
		$options->setOnlyVideo(true)->setLimit($limit);

		return array_values(array_filter(
			$this->filterService->apply($this->streamService->getTimeline($options), $context, $viewer),
			static fn (mixed $status): bool => is_array($status) && is_array($status['video_card'] ?? null)
		));
	}

	/**
	 * The lists one after the other, each video once and the post itself not
	 * at all, cut to the limit.
	 *
	 * @param list<list<array<string, mixed>>> $lists
	 *
	 * @return list<array<string, mixed>>
	 */
	private function merge(array $lists, string $exclude, int $limit): array {
		$seen = [$exclude => true];
		$merged = [];
		foreach ($lists as $list) {
			foreach ($list as $status) {
				$id = (string)($status['id'] ?? '');
				if ($id === '' || isset($seen[$id])) {
					continue;
				}
				$seen[$id] = true;
				$merged[] = $status;
				if (count($merged) >= $limit) {
					return $merged;
				}
			}
		}

		return $merged;
	}

	/** @return string[] the post's hashtags, without the `#` and each once */
	private function hashtagsOf(Stream $post): array {
		$tags = [];
		foreach ($post->getHashtags() as $tag) {
			$tag = ltrim(trim((string)$tag), '#');
			if ($tag !== '') {
				$tags[mb_strtolower($tag, 'UTF-8')] ??= $tag;
			}
		}

		return array_values($tags);
	}

	private function categoryOf(Stream $post): string {
		return trim((string)($post->getVideoMeta()['category'] ?? ''));
	}
}
