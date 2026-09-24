<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use InvalidArgumentException;
use OCA\Social\Service\FeedParserService;
use PHPUnit\Framework\TestCase;

class FeedParserServiceTest extends TestCase {
	private FeedParserService $service;

	protected function setUp(): void {
		$this->service = new FeedParserService();
	}

	/**
	 * A YouTube channel feed, shaped as YouTube actually writes one.
	 *
	 * Everything worth showing is inside `media:group` — which is why reading
	 * only the entry's own children found the title and the date and left the
	 * picture and the description empty.
	 */
	public function testAYouTubeChannelFeedIsReadWholeGroupAndAll(): void {
		$read = $this->service->parse(<<<'XML'
			<?xml version="1.0" encoding="UTF-8"?>
			<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/" xmlns="http://www.w3.org/2005/Atom">
			 <title>Linus Tech Tips</title>
			 <link rel="alternate" href="https://www.youtube.com/channel/UCXuqSBlHAE6Xw-yeJA0Tunw"/>
			 <entry>
			  <id>yt:video:p-EXFD_DHmc</id>
			  <title>leaking the newest lttstore products...</title>
			  <link rel="alternate" href="https://www.youtube.com/shorts/p-EXFD_DHmc"/>
			  <published>2026-09-24T16:25:01+00:00</published>
			  <media:group>
			   <media:content url="https://www.youtube.com/v/p-EXFD_DHmc?version=3" type="application/x-shockwave-flash" width="640" height="390"/>
			   <media:thumbnail url="https://i1.ytimg.com/vi/p-EXFD_DHmc/hqdefault.jpg" width="480" height="360"/>
			   <media:description>lmg.gg/nexigo</media:description>
			  </media:group>
			 </entry>
			</feed>
			XML);

		$this->assertSame('Linus Tech Tips', $read['title']);
		$this->assertSame('https://www.youtube.com/channel/UCXuqSBlHAE6Xw-yeJA0Tunw', $read['site_url']);
		$this->assertCount(1, $read['items']);

		$item = $read['items'][0];
		$this->assertSame('yt:video:p-EXFD_DHmc', $item['guid']);
		$this->assertSame('https://www.youtube.com/shorts/p-EXFD_DHmc', $item['link']);
		$this->assertSame('2026-09-24 16:25:01', $item['published']);
		$this->assertSame('https://i1.ytimg.com/vi/p-EXFD_DHmc/hqdefault.jpg', $item['thumbnail']);
		$this->assertSame('lmg.gg/nexigo', $item['summary']);
	}

	/**
	 * `media:content` is what the entry is made of, not a picture of it.
	 * YouTube puts a Flash player address there, and an <img> pointed at that
	 * draws a broken image.
	 */
	public function testSomethingThatIsNotAPictureIsNotAThumbnail(): void {
		$read = $this->service->parse(<<<'XML'
			<?xml version="1.0"?>
			<feed xmlns:media="http://search.yahoo.com/mrss/" xmlns="http://www.w3.org/2005/Atom">
			 <entry>
			  <id>1</id><title>t</title><link rel="alternate" href="https://example.org/1"/>
			  <media:group>
			   <media:content url="https://example.org/player.swf" type="application/x-shockwave-flash"/>
			  </media:group>
			 </entry>
			</feed>
			XML);

		$this->assertSame('', $read['items'][0]['thumbnail']);
	}

	/** An ordinary blog: RSS 2.0, with the link and date spelled its way. */
	public function testAnRssFeedIsRead(): void {
		$read = $this->service->parse(<<<'XML'
			<?xml version="1.0"?>
			<rss version="2.0"><channel>
			 <title>The Mozilla Blog</title>
			 <link>https://blog.mozilla.org/en/</link>
			 <item>
			  <title>Choose the right window</title>
			  <link>https://blog.mozilla.org/en/firefox/window-types/</link>
			  <guid isPermaLink="false">https://blog.mozilla.org/?p=87109</guid>
			  <description>&lt;p&gt;We spend a lot of time &lt;b&gt;browsing&lt;/b&gt; online.&lt;/p&gt;</description>
			  <pubDate>Thu, 24 Sep 2026 17:00:00 +0000</pubDate>
			 </item>
			</channel></rss>
			XML);

		$this->assertSame('The Mozilla Blog', $read['title']);
		$item = $read['items'][0];
		$this->assertSame('https://blog.mozilla.org/?p=87109', $item['guid']);
		$this->assertSame('2026-09-24 17:00:00', $item['published']);
		// the markup is not shown, and the entities are not shown either
		$this->assertSame('We spend a lot of time browsing online.', $item['summary']);
	}

	/** An Atom entry writes its address in an attribute, and may carry several. */
	public function testAnAtomEntryTakesTheAlternateLink(): void {
		$read = $this->service->parse(<<<'XML'
			<?xml version="1.0"?>
			<feed xmlns="http://www.w3.org/2005/Atom">
			 <title>a feed</title>
			 <entry>
			  <id>tag:example.org,2026:1</id>
			  <title>an entry</title>
			  <link rel="edit" href="https://example.org/edit/1"/>
			  <link rel="alternate" href="https://example.org/read/1"/>
			  <updated>2026-09-24T10:00:00Z</updated>
			 </entry>
			</feed>
			XML);

		$this->assertSame('https://example.org/read/1', $read['items'][0]['link']);
		$this->assertSame('2026-09-24 10:00:00', $read['items'][0]['published']);
	}

	/** RDF is still out there, and is neither of the other two. */
	public function testAnRdfFeedIsRead(): void {
		$read = $this->service->parse(<<<'XML'
			<?xml version="1.0"?>
			<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns="http://purl.org/rss/1.0/">
			 <channel><title>old school</title><link>https://example.org/</link></channel>
			 <item>
			  <title>an entry</title>
			  <link>https://example.org/1</link>
			  <dc:date>2026-09-24T09:00:00Z</dc:date>
			 </item>
			</rdf:RDF>
			XML);

		$this->assertSame('old school', $read['title']);
		$this->assertSame('https://example.org/1', $read['items'][0]['link']);
		$this->assertSame('2026-09-24 09:00:00', $read['items'][0]['published']);
	}

	/**
	 * A feed is a document from somebody else's server. An XML parser that
	 * resolves external entities fetches whatever the document names — a file
	 * off this disk included.
	 */
	public function testAFeedCannotMakeThisServerReadItsOwnDisk(): void {
		$read = $this->service->parse(
			'<?xml version="1.0"?><!DOCTYPE t [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
			. '<rss version="2.0"><channel><title>&x;</title>'
			. '<item><title>&x;</title><link>https://example.org/1</link></item></channel></rss>'
		);

		$this->assertStringNotContainsString('root:', $read['title']);
		$this->assertStringNotContainsString('root:', $read['items'][0]['title']);
	}

	public function testSomethingThatIsNotAFeedSaysSo(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->parse('<html><body>a web page</body></html>');
	}

	public function testRubbishSaysSo(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->parse('not xml at all');
	}

	/** One read takes a bounded number of entries, however long the feed is. */
	public function testAVeryLongFeedIsCutAtTheCeiling(): void {
		$items = str_repeat('<item><title>x</title><link>https://example.org/x</link></item>', FeedParserService::MAX_ITEMS + 20);

		$read = $this->service->parse('<?xml version="1.0"?><rss version="2.0"><channel><title>t</title>' . $items . '</channel></rss>');

		$this->assertCount(FeedParserService::MAX_ITEMS, $read['items']);
	}
}
