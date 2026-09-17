<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub;

use OCA\Social\Model\ActivityPub\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the News timeline is, decided once on the write.
 *
 * `news_kind` is written by `StreamRequest` when a post is stored and read by
 * an index when the timeline is drawn, so a post that is judged wrongly here is
 * wrong in the database until it is edited — no read-time predicate exists to
 * disagree with it. The migration that backfills the column asks this same
 * question of ten million stored rows.
 */
class StreamNewsKindTest extends TestCase {
	/** A mention, as this app writes one. */
	private const MENTION = '<span class="h-card"><a href="https://remote.example/@bob" class="u-url mention">@<span>bob</span></a></span>';

	/** A hashtag, as a remote server writes one. */
	private const HASHTAG = '<a href="https://remote.example/tags/news" class="mention hashtag" rel="tag">#<span>news</span></a>';

	/** @return array<string, array{string, string, string}> */
	public static function posts(): array {
		return [
			'an article is news whatever it says' => [
				'', 'Article', Stream::NEWS_KIND_ARTICLE,
			],
			'so is a Page, which is what some blogs publish' => [
				'<p>a long read</p>', 'Page', Stream::NEWS_KIND_ARTICLE,
			],
			'a post linking to one is news' => [
				'<p>worth reading: <a href="https://paper.example/piece">this</a></p>', '',
				Stream::NEWS_KIND_LINK,
			],
			'a plain-text link counts, for posts written through the API' => [
				'go and read https://paper.example/piece today', '', Stream::NEWS_KIND_LINK,
			],
			'a post with nothing in it is not news' => [
				'<p>good morning</p>', '', Stream::NEWS_KIND_NONE,
			],
			// the trap this whole classifier exists to avoid: a mention and a
			// hashtag are anchors too, and counting them would have put every
			// conversation on the instance in the News timeline
			'a mention is not a link to an article' => [
				'<p>' . self::MENTION . ' good morning</p>', '', Stream::NEWS_KIND_NONE,
			],
			'nor is a hashtag' => [
				'<p>' . self::HASHTAG . ' good morning</p>', '', Stream::NEWS_KIND_NONE,
			],
			'a real link among mentions is still found' => [
				'<p>' . self::MENTION . ' see <a href="https://paper.example/piece">this</a></p>', '',
				Stream::NEWS_KIND_LINK,
			],
			// a video is a kind of post this app already has a page for, and
			// it arrives with its own subtype: it must not be swept in here
			'a PeerTube video is not news' => [
				'<p>my talk</p>', 'Video', Stream::NEWS_KIND_NONE,
			],
			'a link to something that is not a web page does not count' => [
				'<p>write to <a href="mailto:bob@remote.example">bob</a></p>', '',
				Stream::NEWS_KIND_NONE,
			],
		];
	}

	#[DataProvider('posts')]
	public function testNewsKindOf(string $content, string $subType, string $expected): void {
		$this->assertSame($expected, Stream::newsKindOf($content, $subType));
	}

	/**
	 * An article is one whatever its text turns out to hold, so the subtype is
	 * answered before the content is looked at: a blog post full of links is
	 * `article`, not `link`.
	 */
	public function testAnArticleIsNotDemotedByItsOwnLinks(): void {
		$this->assertSame(
			Stream::NEWS_KIND_ARTICLE,
			Stream::newsKindOf('<p><a href="https://paper.example/other">a source</a></p>', 'Article')
		);
	}

	/**
	 * The column is seven characters wide, which is what `article` fits in
	 * exactly. A fourth value longer than that would be silently truncated by
	 * MySQL and simply never match.
	 */
	public function testEveryValueFitsTheColumn(): void {
		foreach ([Stream::NEWS_KIND_NONE, Stream::NEWS_KIND_LINK, Stream::NEWS_KIND_ARTICLE] as $kind) {
			$this->assertLessThanOrEqual(7, strlen($kind), $kind . ' does not fit news_kind');
		}
	}

	/**
	 * The two halves of the app that ask "does this post link anywhere" must
	 * not come to disagree: one decides whether a preview is fetched, the
	 * other whether the post is in the News timeline, and a post that is news
	 * without a card is a row with no headline on it.
	 */
	public function testTheLinkFoundIsTheOneAPreviewWouldBeFetchedFor(): void {
		$content = '<p>' . self::MENTION . ' see <a href="https://paper.example/piece">this</a></p>';

		$this->assertSame('https://paper.example/piece', Stream::firstLinkIn($content));
	}
}
