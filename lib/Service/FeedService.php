<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;

/**
 * An account's public posts as an RSS feed.
 *
 * PeerTube publishes one per channel and per account, and its users live in
 * feed readers and podcast apps: a channel with no feed is one they cannot
 * subscribe to from outside, whatever else it offers. Mastodon publishes one
 * too, at `/@user.rss`, so this is not a PeerTube idea — it is a thing every
 * other implementation has and this app did not.
 *
 * **Public posts only, and nothing else.** The feed is readable signed out, so
 * it is built from the same anonymous read a stranger's visit to the profile
 * gets. A feed is also the easiest possible way to leak a followers-only post
 * — one wrong predicate and it is in somebody's RSS reader and out of reach
 * for ever — so the caller passes posts that were fetched with **no viewer**,
 * and this class refuses anything that is not public a second time.
 *
 * RSS 2.0 rather than Atom, because that is what PeerTube and Mastodon both
 * publish and what every podcast client reads.
 */
class FeedService {
	/** How many posts a feed carries. PeerTube's own is twenty. */
	public const LIMIT = 20;

	public function __construct(
		private ConfigService $configService,
	) {
	}

	/**
	 * @param Stream[] $posts
	 */
	public function forAccount(Person $actor, array $posts): string {
		$title = $actor->getName() !== '' ? $actor->getName() : $actor->getPreferredUsername();
		$link = $actor->getId();

		$items = '';
		foreach ($posts as $post) {
			// the second refusal: a feed is the easiest possible way to leak a
			// followers-only post, and one wrong predicate upstream would put
			// it in somebody's reader and out of reach for ever
			if (!$post->isPublic()) {
				continue;
			}

			$items .= $this->item($post, $actor);
		}

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" '
			. 'xmlns:media="http://search.yahoo.com/mrss/">' . "\n"
			. '<channel>' . "\n"
			. '<title>' . $this->escape($title) . '</title>' . "\n"
			. '<link>' . $this->escape($link) . '</link>' . "\n"
			. '<description>' . $this->escape($this->descriptionOf($actor)) . '</description>' . "\n"
			. '<language>' . $this->escape($actor->getLanguage() !== '' ? $actor->getLanguage() : 'en') . '</language>' . "\n"
			. '<atom:link href="' . $this->escape($link . '.rss') . '" rel="self" type="application/rss+xml"/>' . "\n"
			. $items
			. '</channel>' . "\n"
			. '</rss>' . "\n";
	}

	/**
	 * One post as an item.
	 *
	 * A video carries an `enclosure`, which is what makes the feed usable in a
	 * podcast client: that is the whole reason PeerTube publishes one.
	 */
	private function item(Stream $post, Person $actor): string {
		$title = $this->titleOf($post, $actor);
		$link = $post->getId();

		$item = '<item>' . "\n"
			. '<title>' . $this->escape($title) . '</title>' . "\n"
			. '<link>' . $this->escape($link) . '</link>' . "\n"
			. '<guid isPermaLink="true">' . $this->escape($link) . '</guid>' . "\n"
			. '<pubDate>' . gmdate('D, d M Y H:i:s O', $post->getPublishedTime()) . '</pubDate>' . "\n"
			. '<description>' . $this->escape($post->getContent()) . '</description>' . "\n";

		foreach ($post->getAttachments() as $attachment) {
			$url = (string)$attachment->getUrl();
			if ($url === '' || $attachment->getType() !== 'video') {
				continue;
			}

			// one enclosure per item is what RSS allows and what a podcast
			// client reads; a post with several videos is not a video post
			$item .= '<enclosure url="' . $this->escape($url) . '" type="'
				. $this->escape($attachment->getMediaType() !== '' ? $attachment->getMediaType() : 'video/mp4')
				. '" length="' . $attachment->getSizeBytes() . '"/>' . "\n";
			break;
		}

		return $item . '</item>' . "\n";
	}

	/**
	 * What to call a post in a list of titles.
	 *
	 * A video has a name; everything else has to make do with its first line,
	 * which is what a feed reader shows in a list and what somebody writing a
	 * post puts first anyway.
	 */
	private function titleOf(Stream $post, Person $actor): string {
		$video = $post->getVideoMeta();
		if (($video['title'] ?? '') !== '') {
			return (string)$video['title'];
		}

		$text = trim(html_entity_decode(
			strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $post->getContent())),
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		));
		$first = trim((string)strtok($text, "\n"));

		return ($first !== '') ? mb_substr($first, 0, 120) : $actor->getPreferredUsername();
	}

	private function descriptionOf(Person $actor): string {
		$bio = trim(strip_tags($actor->getDescription()));

		return ($bio !== '') ? $bio : $actor->getPreferredUsername() . ' on ' . $this->host();
	}

	private function host(): string {
		try {
			return $this->configService->getCloudHost();
		} catch (\Throwable $e) {
			return '';
		}
	}

	private function escape(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
	}
}
