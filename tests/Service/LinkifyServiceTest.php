<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\LinkifyService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The plain text somebody types becomes the HTML every peer publishes:
 * paragraphs, and links for URLs, mentions and hashtags. Everything here is
 * about two things — that the user's text is escaped before any markup exists,
 * and that a link is only ever emitted for an entity the `tag` array vouches
 * for.
 */
class LinkifyServiceTest extends TestCase {
	private const BOB = 'https://remote.example/users/bob';
	private const TAG_URL = 'https://cloud.example/apps/social/timeline/tags/nextcloud';

	private LinkifyService $service;

	protected function setUp(): void {
		$this->service = new LinkifyService();
	}

	/** @return array<int, array<string, string>> */
	private function tags(): array {
		return [
			['type' => 'Mention', 'href' => self::BOB, 'name' => '@bob@remote.example'],
			['type' => 'Hashtag', 'href' => self::TAG_URL, 'name' => '#Nextcloud'],
		];
	}

	// paragraphs and escaping

	public function testTextBecomesAParagraph(): void {
		$this->assertSame('<p>hello</p>', $this->service->toHtml('hello', []));
	}

	/**
	 * A bare newline is whitespace in HTML, so a post sent with newlines and no
	 * markup arrives on every peer as one run-on line.
	 */
	public function testNewlinesBecomeBreaksAndBlankLinesBecomeParagraphs(): void {
		$this->assertSame(
			'<p>one<br />two</p><p>three</p>',
			$this->service->toHtml("one\ntwo\n\nthree", [])
		);
	}

	public function testCarriageReturnsAreNewlinesToo(): void {
		$this->assertSame(
			'<p>one<br />two</p><p>three</p>',
			$this->service->toHtml("one\r\ntwo\r\n\r\nthree", [])
		);
	}

	public function testTextThatIsOnlyWhitespaceProducesNothing(): void {
		$this->assertSame('', $this->service->toHtml("\n\n  \n", []));
		$this->assertSame('', $this->service->toHtml('', []));
	}

	/** The text is escaped before any markup exists, never un-escaped after. */
	public function testMarkupInTheTextIsEscaped(): void {
		$this->assertSame(
			'<p>&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; it&#039;s fine</p>',
			$this->service->toHtml('<script>alert("x")</script> & it\'s fine', [])
		);
	}

	/**
	 * The label of a link is the matched text, and it is escaped like the rest:
	 * an entity is never a way of smuggling markup past the escaping.
	 */
	public function testAnEntityCannotCarryMarkupIntoTheOutput(): void {
		$html = $this->service->toHtml('<b>#Nextcloud</b>', $this->tags());

		$this->assertStringContainsString('&lt;b&gt;', $html);
		$this->assertStringNotContainsString('<b>', $html);
	}

	// URLs

	public function testAUrlBecomesALink(): void {
		$this->assertSame(
			'<p>see <a href="https://example.invalid/a" rel="nofollow noopener noreferrer">'
			. 'https://example.invalid/a</a></p>',
			$this->service->toHtml('see https://example.invalid/a', [])
		);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function urlBoundaryProvider(): array {
		return [
			'a full stop ends the sentence, not the URL' => ['https://example.invalid/a.', 'https://example.invalid/a'],
			'a comma likewise' => ['https://example.invalid/a,', 'https://example.invalid/a'],
			'an unopened bracket is not part of it' => ['(https://example.invalid/a)', 'https://example.invalid/a'],
			'a bracket the URL opened is' => ['https://example.invalid/a_(b)', 'https://example.invalid/a_(b)'],
			'a query string is' => ['https://example.invalid/a?b=c&d=e', 'https://example.invalid/a?b=c&d=e'],
			'and so is a fragment' => ['https://example.invalid/a#section', 'https://example.invalid/a#section'],
		];
	}

	#[DataProvider('urlBoundaryProvider')]
	public function testAUrlEndsWhereTheSentenceDoes(string $text, string $expected): void {
		$entities = $this->service->entitiesIn($text);

		$this->assertCount(1, $entities);
		$this->assertSame(LinkifyService::TYPE_URL, $entities[0]['type']);
		$this->assertSame($expected, $entities[0]['name']);
	}

	/**
	 * Everything inside a URL belongs to it: a fragment is not a hashtag and a
	 * path segment is not an account, however much they look like one.
	 */
	public function testNothingInsideAUrlIsAMentionOrAHashtag(): void {
		$entities = $this->service->entitiesIn('https://example.invalid/@bob#section');

		$this->assertCount(1, $entities);
		$this->assertSame(LinkifyService::TYPE_URL, $entities[0]['type']);
	}

	public function testOnlyHttpUrlsAreLinked(): void {
		$this->assertSame([], $this->service->entitiesIn('javascript:alert(1) ftp://example.invalid/a'));
	}

	// mentions

	public function testAMentionTheTagsVouchForBecomesALink(): void {
		$this->assertSame(
			'<p>hi <a href="' . self::BOB . '" class="u-url mention" rel="nofollow noopener noreferrer">'
			. '@bob@remote.example</a></p>',
			$this->service->toHtml('hi @bob@remote.example', $this->tags())
		);
	}

	/**
	 * The markup is built from the `tag` array, so it can never name somebody
	 * the tags do not — which is the list a receiving instance checks a mention
	 * against before it notifies anyone.
	 */
	public function testAMentionWithNoTagStaysText(): void {
		$this->assertSame(
			'<p>hi @nobody@elsewhere.invalid</p>',
			$this->service->toHtml('hi @nobody@elsewhere.invalid', $this->tags())
		);
	}

	public function testAHandleIsMatchedWithoutRegardToCase(): void {
		$this->assertStringContainsString(
			'href="' . self::BOB . '"',
			$this->service->toHtml('hi @Bob@Remote.Example', $this->tags())
		);
	}

	// hashtags

	public function testAHashtagTheTagsVouchForBecomesALink(): void {
		$this->assertSame(
			'<p><a href="' . self::TAG_URL . '" class="mention hashtag" rel="tag">#Nextcloud</a> rocks</p>',
			$this->service->toHtml('#Nextcloud rocks', $this->tags())
		);
	}

	public function testAHashtagWithNoTagStaysText(): void {
		$this->assertSame('<p>#other</p>', $this->service->toHtml('#other', $this->tags()));
	}

	// what a tag may not do

	/**
	 * A tag's href comes from a cached remote actor or from a generated URL;
	 * neither is a reason to emit a scheme this app would strip out of a post
	 * arriving from a peer.
	 */
	public function testATagHrefWithARefusedSchemeIsNotLinked(): void {
		$html = $this->service->toHtml('hi @bob@remote.example', [
			['type' => 'Mention', 'href' => 'javascript:alert(1)', 'name' => '@bob@remote.example'],
		]);

		$this->assertSame('<p>hi @bob@remote.example</p>', $html);
	}

	public function testAQuoteInATagHrefCannotEndTheAttribute(): void {
		$html = $this->service->toHtml('hi @bob@remote.example', [
			[
				'type' => 'Mention',
				'href' => 'https://remote.example/" onmouseover="alert(1)',
				'name' => '@bob@remote.example',
			],
		]);

		$this->assertStringNotContainsString('onmouseover="alert(1)"', $html);
		$this->assertStringContainsString('&quot; onmouseover=&quot;alert(1)', $html);
	}

	// entitiesIn(), which is also what PostService addresses a post from

	/**
	 * @return array<string, array{string, array<int, array{string, string}>}>
	 */
	public static function entityProvider(): array {
		return [
			'a mention and a hashtag' => ['hi @bob@remote.example #Nextcloud', [
				[LinkifyService::TYPE_MENTION, 'bob@remote.example'],
				[LinkifyService::TYPE_HASHTAG, 'Nextcloud'],
			]],
			'a local handle has no domain' => ['@alice', [[LinkifyService::TYPE_MENTION, 'alice']]],
			'an email address is not a mention' => ['write to frank@example.org', []],
			'a hash inside a word is not a tag' => ['issue#42 is fixed', []],
			'an html entity is not a tag' => ['a &#39; b', []],
			'a sentence may end after a handle' => ['ask @bob@remote.example.', [
				[LinkifyService::TYPE_MENTION, 'bob@remote.example'],
			]],
			'a handle in brackets is still a handle' => ['(@bob@remote.example)', [
				[LinkifyService::TYPE_MENTION, 'bob@remote.example'],
			]],
			'nothing at all' => ['plain words', []],
		];
	}

	/**
	 * @param array<int, array{string, string}> $expected
	 */
	#[DataProvider('entityProvider')]
	public function testEntitiesAreFoundWhereTheyAre(string $text, array $expected): void {
		$found = array_map(
			static fn (array $entity): array => [$entity['type'], $entity['name']],
			$this->service->entitiesIn($text)
		);

		$this->assertSame($expected, $found);
	}

	/** The offset and length are what the renderer cuts the text at. */
	public function testAnEntityNamesItsOwnPlaceInTheText(): void {
		$text = 'hi @bob@remote.example!';
		$entity = $this->service->entitiesIn($text)[0];

		$this->assertSame(
			$entity['text'],
			substr($text, $entity['offset'], strlen($entity['text']))
		);
		$this->assertSame('@bob@remote.example', $entity['text']);
	}

	public function testEveryEntityInALongerTextIsLinked(): void {
		$html = $this->service->toHtml(
			"@bob@remote.example look:\nhttps://example.invalid/a #Nextcloud",
			$this->tags()
		);

		$this->assertSame(
			'<p><a href="' . self::BOB . '" class="u-url mention" rel="nofollow noopener noreferrer">'
			. '@bob@remote.example</a> look:<br />'
			. '<a href="https://example.invalid/a" rel="nofollow noopener noreferrer">https://example.invalid/a</a> '
			. '<a href="' . self::TAG_URL . '" class="mention hashtag" rel="tag">#Nextcloud</a></p>',
			$html
		);
	}
}
