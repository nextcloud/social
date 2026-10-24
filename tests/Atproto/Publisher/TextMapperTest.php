<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Publisher\TextMapper;
use PHPUnit\Framework\TestCase;

/**
 * HTML to Bluesky text: the shapes LinkifyService writes, the whitespace
 * that paragraphs and breaks become, facets on byte offsets of multi-byte
 * text, and the cut at 280 graphemes with the link appended.
 */
class TextMapperTest extends TestCase {
	private TextMapper $mapper;

	protected function setUp(): void {
		$this->mapper = new TextMapper();
	}

	public function testParagraphsAndBreaks(): void {
		$result = $this->mapper->fromHtml('<p>First line<br />second line</p><p>Second paragraph</p>', self::noDid());

		$this->assertSame("First line\nsecond line\n\nSecond paragraph", $result['text']);
		$this->assertSame([], $result['facets']);
	}

	public function testEmptyParagraphsDoNotPileUp(): void {
		$result = $this->mapper->fromHtml('<p></p><p>  </p><p>Only</p><p></p>', self::noDid());

		$this->assertSame('Only', $result['text']);
	}

	public function testALinkWhoseTextIsItsHrefBecomesOneLinkFacet(): void {
		$url = 'https://example.org/path?q=1';
		$result = $this->mapper->fromHtml('<p>Read <a href="' . $url . '" rel="nofollow noopener noreferrer">' . $url . '</a> now</p>', self::noDid());

		$this->assertSame('Read ' . $url . ' now', $result['text']);
		$this->assertSame([[
			'index' => ['byteStart' => 5, 'byteEnd' => 5 + strlen($url)],
			'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]],
		]], $result['facets']);
	}

	public function testALinkWithOtherTextKeepsBothAndFacetsTheUrl(): void {
		$result = $this->mapper->fromHtml('<a href="https://example.org/">the site</a>', self::noDid());

		$this->assertSame('the site (https://example.org/)', $result['text']);
		$this->assertSame(['byteStart' => 10, 'byteEnd' => 30], $result['facets'][0]['index']);
	}

	public function testAHashtagIsATagFacet(): void {
		$result = $this->mapper->fromHtml('<p>Über <a href="https://s.example/tags/Nextcloud" class="mention hashtag" rel="tag">#Nextcloud</a></p>', self::noDid());

		$this->assertSame('Über #Nextcloud', $result['text']);
		// Ü is two bytes: the facet is on bytes, not characters
		$this->assertSame([[
			'index' => ['byteStart' => 6, 'byteEnd' => 16],
			'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'Nextcloud']],
		]], $result['facets']);
	}

	public function testAMentionWithADidIsAMentionFacet(): void {
		$html = '<span class="h-card"><a href="https://s.example/@bob" class="u-url mention" rel="nofollow noopener noreferrer">@bob@s.example</a></span> hi';
		$result = $this->mapper->fromHtml($html, static fn (string $text, string $href): ?string => $href === 'https://s.example/@bob' ? 'did:plc:bob' : null);

		$this->assertSame('@bob@s.example hi', $result['text']);
		$this->assertSame([['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:bob']], $result['facets'][0]['features']);
		$this->assertSame(['byteStart' => 0, 'byteEnd' => 14], $result['facets'][0]['index']);
	}

	public function testAMentionWithoutADidLinksToTheProfile(): void {
		$result = $this->mapper->fromHtml('<a href="https://s.example/@bob" class="u-url mention">@bob@s.example</a>', self::noDid());

		$this->assertSame([['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://s.example/@bob']], $result['facets'][0]['features']);
	}

	public function testEmojiImagesBecomeTheirText(): void {
		$result = $this->mapper->fromHtml('<p>Hi <img class="emoji" src="x.png" alt=":wave:" /> there</p>', self::noDid());

		$this->assertSame('Hi :wave: there', $result['text']);
	}

	public function testMarkupThatIsNotHtmlIsStillText(): void {
		$result = $this->mapper->fromHtml('a &lt; b &amp; c &gt; d &quot;e&quot;', self::noDid());

		$this->assertSame('a < b & c > d "e"', $result['text']);
	}

	public function testNothingToCutLeavesTheTextAlone(): void {
		$fit = $this->mapper->fit('short', [], 'https://s.example/@a/1');

		$this->assertSame(['text' => 'short', 'facets' => [], 'truncated' => false], $fit);
	}

	public function testALongPostIsCutAtAWordAndLinked(): void {
		$words = implode(' ', array_fill(0, 100, 'word'));
		$url = 'https://s.example/@alice/123';
		$fit = $this->mapper->fit($words, [], $url);

		$this->assertTrue($fit['truncated']);
		$this->assertLessThanOrEqual(TextMapper::MAX_GRAPHEMES, Lexicon::graphemes($fit['text']));
		$this->assertStringEndsWith("…\n\n" . $url, $fit['text']);
		$this->assertMatchesRegularExpression('/word…/', $fit['text'], 'cut on a word boundary');
		$last = end($fit['facets']);
		$this->assertSame(['byteStart' => strlen($fit['text']) - strlen($url), 'byteEnd' => strlen($fit['text'])], $last['index']);
		$this->assertSame($url, $last['features'][0]['uri']);
	}

	public function testAFullLongPostKeepsItsSocialLinkAfterTheLastThreadPart(): void {
		$words = array_map(static fn (int $word): string => 'word' . $word, range(1, 100));
		$url = 'https://s.example/@alice/123';
		$fit = $this->mapper->fit(implode(' ', $words), [], $url);

		$this->assertArrayHasKey('threadParts', $fit);
		$parts = $fit['threadParts'];
		$this->assertGreaterThan(1, count($parts));
		$this->assertStringNotContainsString($url, $parts[0]['text'], 'the feed preview is not ended with a Social link');
		$threadText = implode(' ', array_column($parts, 'text'));
		$withoutWhitespace = static fn (string $text): string => preg_replace('/\\s+/u', '', $text) ?? $text;
		$this->assertStringStartsWith(
			$withoutWhitespace(implode(' ', $words)),
			$withoutWhitespace($threadText),
			'the complete source body stays in order at the start of the thread'
		);
		foreach ($words as $word) {
			$this->assertStringContainsString($word, $threadText);
		}
		$this->assertStringContainsString($url, $parts[array_key_last($parts)]['text'], 'the Social link comes after all thread parts');
	}

	public function testAResolvedMentionAtAThreadBoundaryKeepsItsFacetWhole(): void {
		$mention = '@bob@example.test';
		$text = str_repeat('x', 295) . $mention . str_repeat('y', 40);
		$facets = [[
			'index' => ['byteStart' => 295, 'byteEnd' => 295 + strlen($mention)],
			'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:bob']],
		]];
		$fit = $this->mapper->fit($text, $facets, 'https://s.example/@alice/123');

		$this->assertArrayHasKey('threadParts', $fit);
		$parts = $fit['threadParts'];
		$this->assertStringEndsWith(str_repeat('x', 295), $parts[0]['text']);
		$this->assertStringStartsWith($mention, $parts[1]['text']);
		$this->assertSame([
			'index' => ['byteStart' => 0, 'byteEnd' => strlen($mention)],
			'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:bob']],
		], $parts[1]['facets'][0]);
	}

	public function testLongSocialPageUrlIsDisplayedShortButLinksToTheFullPost(): void {
		$url = 'https://social.example/' . str_repeat('nested-path/', 35) . 'post/123';
		$fit = $this->mapper->fit(str_repeat('The full post stays readable. ', 20), [], $url);

		$this->assertTrue($fit['truncated']);
		$this->assertLessThanOrEqual(TextMapper::MAX_GRAPHEMES, Lexicon::graphemes($fit['text']));
		$this->assertLessThanOrEqual(TextMapper::MAX_BYTES, strlen($fit['text']));
		$link = end($fit['facets']);
		$this->assertSame($url, $link['features'][0]['uri']);
		$displayUrl = substr($fit['text'], $link['index']['byteStart'], $link['index']['byteEnd'] - $link['index']['byteStart']);
		$this->assertNotSame($url, $displayUrl);
		$this->assertStringEndsWith('…', $displayUrl);
	}

	public function testTheCutCountsGraphemesNotBytes(): void {
		$text = str_repeat('👨‍👩‍👧‍👧 ', 150);
		$fit = $this->mapper->fit($text, [], 'https://s.example/p');

		$this->assertTrue($fit['truncated']);
		$this->assertLessThanOrEqual(TextMapper::MAX_GRAPHEMES, Lexicon::graphemes($fit['text']));
		$this->assertLessThanOrEqual(TextMapper::MAX_BYTES, strlen($fit['text']));
		$this->assertTrue(mb_check_encoding($fit['text'], 'UTF-8'));
	}

	public function testAFacetPastTheCutIsDroppedAndOneBeforeItKept(): void {
		$text = str_repeat('a', 100) . ' #early ' . str_repeat('b', 200) . ' #late';
		$facets = TextMapper::facetsOf($text);
		$this->assertCount(2, $facets);

		$fit = $this->mapper->fit($text, $facets, 'https://s.example/p');

		$tags = array_map(static fn (array $f): string => $f['features'][0]['tag'] ?? '', $fit['facets']);
		$this->assertContains('early', $tags);
		$this->assertNotContains('late', $tags);
	}

	public function testExtraLinesAreAddedWithTheLinkEvenWhenNothingIsCut(): void {
		$fit = $this->mapper->fit('With pictures', [], 'https://s.example/p', ['+2 more pictures']);

		$this->assertFalse($fit['truncated']);
		$this->assertSame("With pictures\n\n+2 more pictures\n\nhttps://s.example/p", $fit['text']);
	}

	public function testOversizedExtraLinesStayInTheThreadWithoutMakingTheRootInvalid(): void {
		$extras = [str_repeat('alpha-reference ', 30), str_repeat('beta-reference ', 30)];
		$fit = $this->mapper->fit(str_repeat('Opening sentence. ', 40), [], 'https://s.example/p', $extras);

		$this->assertLessThanOrEqual(TextMapper::MAX_GRAPHEMES, Lexicon::graphemes($fit['text']));
		$this->assertLessThanOrEqual(TextMapper::MAX_BYTES, strlen($fit['text']));
		$this->assertStringContainsString('Opening sentence.', $fit['text']);
		$this->assertArrayHasKey('threadParts', $fit);
		$threadText = implode(' ', array_column($fit['threadParts'], 'text'));
		$this->assertSame(30, substr_count($threadText, 'alpha-reference'));
		$this->assertSame(30, substr_count($threadText, 'beta-reference'));
		foreach ($fit['threadParts'] as $part) {
			$this->assertLessThanOrEqual(TextMapper::MAX_GRAPHEMES, Lexicon::graphemes($part['text']));
			$this->assertLessThanOrEqual(TextMapper::MAX_BYTES, strlen($part['text']));
		}
	}

	public function testExtraLinksDoNotReplaceTheSocialPostLinkInAThread(): void {
		$postUrl = 'https://s.example/@alice/123';
		$extraUrl = 'https://images.example/picture';
		$fit = $this->mapper->fit(str_repeat('A long post. ', 40), [], $postUrl, ['More pictures: ' . $extraUrl]);

		$this->assertArrayHasKey('threadParts', $fit);
		$rootLink = end($fit['facets']);
		$this->assertSame($postUrl, $rootLink['features'][0]['uri']);

		$threadLinks = [];
		foreach ($fit['threadParts'] as $part) {
			foreach ($part['facets'] as $facet) {
				$threadLinks[] = $facet['features'][0]['uri'] ?? '';
			}
		}
		$this->assertContains($postUrl, $threadLinks);
		$this->assertContains($extraUrl, $threadLinks);
	}

	public function testFacetsOfPlainText(): void {
		$facets = TextMapper::facetsOf('See https://example.org/a. And #tag2 #ünï');

		$this->assertSame('https://example.org/a', $facets[0]['features'][0]['uri']);
		$this->assertSame(['byteStart' => 4, 'byteEnd' => 25], $facets[0]['index']);
		$this->assertSame('tag2', $facets[1]['features'][0]['tag']);
		$this->assertSame('ünï', $facets[2]['features'][0]['tag']);
	}

	/**
	 * @return callable(string, string): ?string
	 */
	private static function noDid(): callable {
		return static fn (): ?string => null;
	}
}
