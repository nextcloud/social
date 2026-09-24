<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\FeedItemsRequest;
use OCA\Social\Db\FeedsRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\Feed;
use OCA\Social\Model\FeedItem;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\SubscriptionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class SubscriptionServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';

	private FeedsRequest|MockObject $feedsRequest;
	private FeedItemsRequest|MockObject $feedItemsRequest;
	private CurlService|MockObject $curlService;
	private SubscriptionService $service;

	/** @var FeedItem[] what the run wrote */
	private array $written = [];
	/** @var Feed[] the rows it updated */
	private array $updated = [];

	protected function setUp(): void {
		parent::setUp();

		$this->feedsRequest = $this->createMock(FeedsRequest::class);
		$this->feedsRequest->method('save')->willReturn(true);
		$this->feedsRequest->method('idsOf')->willReturn([]);
		$this->feedsRequest->method('updateFetch')
			->willReturnCallback(function (Feed $feed): void {
				$this->updated[] = clone $feed;
			});

		$this->feedItemsRequest = $this->createMock(FeedItemsRequest::class);
		$this->feedItemsRequest->method('save')
			->willReturnCallback(function (FeedItem $item): bool {
				$this->written[] = $item;

				return true;
			});

		$this->curlService = $this->createMock(CurlService::class);

		$this->service = new SubscriptionService(
			$this->feedsRequest,
			$this->feedItemsRequest,
			$this->curlService,
			new NullLogger(),
		);
	}

	/** What `CurlService::openStream()` hands back, for a body written here. */
	private function answers(string $body, int $status = 200, array $headers = []): void {
		$this->curlService->method('openStream')
			->willReturnCallback(static function () use ($body, $status, $headers): array {
				$stream = fopen('php://temp', 'r+');
				fwrite($stream, $body);
				rewind($stream);

				return ['stream' => $stream, 'status' => $status, 'headers' => $headers];
			});
	}

	private function feed(string $url = 'https://example.org/feed'): Feed {
		$feed = new Feed();
		$feed->setId(7)->setActorId(self::ALICE)->setUrl($url);

		return $feed;
	}

	// normalise()

	/**
	 * A channel id is what people have; the feed address is what they would
	 * never find.
	 */
	public function testAYoutubeChannelIdBecomesItsFeed(): void {
		$this->assertSame(
			'https://www.youtube.com/feeds/videos.xml?channel_id=UCXuqSBlHAE6Xw-yeJA0Tunw',
			$this->service->normalise('UCXuqSBlHAE6Xw-yeJA0Tunw')
		);
	}

	public function testAChannelLinkBecomesItsFeed(): void {
		$this->assertSame(
			'https://www.youtube.com/feeds/videos.xml?channel_id=UCXuqSBlHAE6Xw-yeJA0Tunw',
			$this->service->normalise('https://www.youtube.com/channel/UCXuqSBlHAE6Xw-yeJA0Tunw/videos')
		);
	}

	/**
	 * A handle names the channel without saying which it is, and resolving one
	 * means fetching the page — a much larger promise than "the address of a
	 * feed". It is left as it was typed and fails as an ordinary address.
	 */
	public function testAYoutubeHandleIsNotGuessedAt(): void {
		$this->assertSame(
			'https://www.youtube.com/@someone',
			$this->service->normalise('https://www.youtube.com/@someone')
		);
	}

	public function testAnOrdinaryFeedAddressIsKept(): void {
		$this->assertSame(
			'https://example.org/feed.xml', $this->service->normalise(' https://example.org/feed.xml ')
		);
	}

	/**
	 * A feed address is a URL somebody typed, and a scheme that is not the web
	 * is a way to ask this server to open something that is not a feed.
	 */
	public function testSomethingThatIsNotAWebAddressIsRefused(): void {
		$this->expectException(InvalidResourceException::class);
		$this->service->normalise('file:///etc/passwd');
	}

	public function testAnAddressTooLongToStoreIsRefused(): void {
		$this->expectException(InvalidResourceException::class);
		$this->service->normalise('https://example.org/' . str_repeat('a', 300));
	}

	// poll(): Atom

	public function testAnAtomFeedIsReadAndItsEntriesWritten(): void {
		$this->answers(<<<'XML'
			<?xml version="1.0" encoding="UTF-8"?>
			<feed xmlns="http://www.w3.org/2005/Atom">
				<title>A channel</title>
				<link rel="self" href="https://example.org/feed"/>
				<link rel="alternate" href="https://example.org/"/>
				<entry>
					<id>yt:video:abc</id>
					<title>The tide coming in</title>
					<link rel="alternate" href="https://example.org/watch/abc"/>
					<published>2026-01-02T10:00:00+00:00</published>
					<author><name>Someone</name></author>
				</entry>
			</feed>
			XML);
		$feed = $this->feed();

		$written = $this->service->poll($feed);

		$this->assertSame(1, $written);
		$this->assertSame('A channel', $feed->getTitle());
		// the reader is sent to the page, not back to the XML they came from
		$this->assertSame('https://example.org/', $feed->getSiteUrl());
		$this->assertSame('The tide coming in', $this->written[0]->getTitle());
		$this->assertSame('https://example.org/watch/abc', $this->written[0]->getLink());
		$this->assertSame('yt:video:abc', $this->written[0]->getGuid());
		$this->assertSame('Someone', $this->written[0]->getAuthor());
		$this->assertSame(
			strtotime('2026-01-02T10:00:00+00:00'), $this->written[0]->getPublished()->getTimestamp()
		);
	}

	/**
	 * An Atom link with no `rel` is an alternate, which the specification says
	 * and half the feeds in the world rely on.
	 */
	public function testALinkWithNoRelIsThePageToOpen(): void {
		$this->answers(<<<'XML'
			<?xml version="1.0"?>
			<feed xmlns="http://www.w3.org/2005/Atom">
				<entry><title>One</title><link href="https://example.org/one"/></entry>
			</feed>
			XML);

		$this->service->poll($this->feed());

		$this->assertSame('https://example.org/one', $this->written[0]->getLink());
	}

	/** YouTube keeps the description and the still under `media:group`. */
	public function testYoutubesDescriptionAndThumbnailAreRead(): void {
		$this->answers(<<<'XML'
			<?xml version="1.0"?>
			<feed xmlns="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">
				<entry>
					<title>A video</title>
					<link rel="alternate" href="https://youtube.com/watch?v=abc"/>
					<media:group>
						<media:description>What happens in it.</media:description>
						<media:thumbnail url="https://i.ytimg.com/vi/abc/hq.jpg"/>
					</media:group>
				</entry>
			</feed>
			XML);

		$this->service->poll($this->feed());

		$this->assertSame('What happens in it.', $this->written[0]->getSummary());
		$this->assertSame('https://i.ytimg.com/vi/abc/hq.jpg', $this->written[0]->getThumbnail());
	}

	// poll(): RSS

	public function testAnRssFeedIsReadToo(): void {
		$this->answers(<<<'XML'
			<?xml version="1.0"?>
			<rss version="2.0">
				<channel>
					<title>A blog</title>
					<link>https://blog.example/</link>
					<item>
						<guid>https://blog.example/1</guid>
						<title>A post</title>
						<link>https://blog.example/1</link>
						<description>&lt;p&gt;With &lt;b&gt;markup&lt;/b&gt; in it.&lt;/p&gt;</description>
						<pubDate>Tue, 06 Jan 2026 09:00:00 +0000</pubDate>
					</item>
				</channel>
			</rss>
			XML);
		$feed = $this->feed();

		$this->assertSame(1, $this->service->poll($feed));
		$this->assertSame('A blog', $feed->getTitle());
		$this->assertSame('https://blog.example/', $feed->getSiteUrl());
		// somebody else's HTML, put in front of a reader: the markup comes out
		$this->assertSame('With markup in it.', $this->written[0]->getSummary());
	}

	/**
	 * `??` does not choose between the two dialects — a missing child of a
	 * SimpleXMLElement is an empty element, not null — so an Atom feed used to
	 * be described from nothing.
	 */
	public function testAnAtomFeedIsDescribedFromItsRootAndNotFromNothing(): void {
		$this->answers(<<<'XML'
			<?xml version="1.0"?>
			<feed xmlns="http://www.w3.org/2005/Atom"><title>Named</title></feed>
			XML);
		$feed = $this->feed();

		$this->service->poll($feed);

		$this->assertSame('Named', $feed->getTitle());
	}

	/** A feed that names neither keeps the name it was subscribed under. */
	public function testAFeedThatNamesItselfNothingKeepsTheNameItHad(): void {
		$this->answers('<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"/>');
		$feed = $this->feed();
		$feed->setTitle('example.org');

		$this->service->poll($feed);

		$this->assertSame('example.org', $feed->getTitle());
	}

	// the conditional request

	public function testTheTwoConditionalHeadersAreKeptForTheNextFetch(): void {
		$this->answers(
			'<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"/>',
			200,
			['ETag' => ['"abc123"'], 'Last-Modified' => ['Tue, 06 Jan 2026 09:00:00 GMT']]
		);
		$feed = $this->feed();

		$this->service->poll($feed);

		$this->assertSame('"abc123"', $feed->getEtag());
		$this->assertSame('Tue, 06 Jan 2026 09:00:00 GMT', $feed->getModifiedAt());
	}

	public function testTheyGoOutOnTheNextFetch(): void {
		$sent = [];
		$this->curlService->method('openStream')
			->willReturnCallback(static function (string $url, array $headers) use (&$sent): array {
				$sent = $headers;
				$stream = fopen('php://temp', 'r+');
				rewind($stream);

				return ['stream' => $stream, 'status' => 304, 'headers' => []];
			});
		$feed = $this->feed();
		$feed->setEtag('"abc123"')->setModifiedAt('Tue, 06 Jan 2026 09:00:00 GMT');

		$this->service->poll($feed);

		$this->assertSame('"abc123"', $sent['If-None-Match']);
		$this->assertSame('Tue, 06 Jan 2026 09:00:00 GMT', $sent['If-Modified-Since']);
	}

	/** 304 is the whole point: nothing to read, nothing written, no error. */
	public function testANotModifiedAnswerWritesNothingAndIsNotAFailure(): void {
		$this->answers('', 304);
		$feed = $this->feed();
		$feed->setFailures(3);

		$this->assertSame(0, $this->service->poll($feed));
		$this->assertSame([], $this->written);
		$this->assertSame(0, $feed->getFailures());
		$this->assertSame('', $feed->getError());
	}

	// what goes wrong

	/**
	 * A poller that gave up on the first unreachable host would leave every
	 * subscription behind it unfetched, so this is kept on the row instead.
	 */
	public function testAHostThatCannotBeReachedIsRecordedRatherThanThrown(): void {
		$this->curlService->method('openStream')
			->willThrowException(new RuntimeException('could not connect'));
		$feed = $this->feed();

		$this->assertSame(0, $this->service->poll($feed));
		$this->assertStringContainsString('could not connect', $feed->getError());
		$this->assertSame(1, $feed->getFailures());
		$this->assertCount(1, $this->updated);
	}

	/**
	 * The message the HTTP client raises is written for a log. On a page
	 * listing what somebody follows it is four lines of noise around one
	 * useful clause, and the address is already on the row beside it.
	 */
	public function testALogShapedErrorIsTrimmedToTheSentence(): void {
		$this->curlService->method('openStream')->willThrowException(new RuntimeException(
			'cURL error 6: Could not resolve host: blog.example'
			. ' (see https://curl.se/libcurl/c/libcurl-errors.html)'
			. ' for https://blog.example/feed - https://blog.example/feed'
		));
		$feed = $this->feed();

		$this->service->poll($feed);

		$this->assertSame('cURL error 6: Could not resolve host: blog.example', $feed->getError());
	}

	public function testSomethingThatIsNotAFeedIsRecordedRatherThanThrown(): void {
		$this->answers('<html><body>a web page</body></html>');
		$feed = $this->feed();

		$this->assertSame(0, $this->service->poll($feed));
		$this->assertNotSame('', $feed->getError());
		$this->assertSame(1, $feed->getFailures());
	}

	public function testADocumentPastTheCeilingIsRefused(): void {
		$this->answers(str_repeat('a', SubscriptionService::MAX_SIZE + 10));
		$feed = $this->feed();

		$this->service->poll($feed);

		$this->assertStringContainsString('larger than', $feed->getError());
	}

	/**
	 * A feed is a file from a server nobody here controls, and an XML parser
	 * will otherwise resolve an external entity — which is a way to read this
	 * server's own files.
	 */
	public function testAnExternalEntityIsNotResolved(): void {
		$this->answers(<<<'XML'
			<?xml version="1.0"?>
			<!DOCTYPE feed [<!ENTITY secret SYSTEM "file:///etc/passwd">]>
			<feed xmlns="http://www.w3.org/2005/Atom">
				<entry><title>&secret;</title><link href="https://example.org/1"/></entry>
			</feed>
			XML);

		$this->service->poll($this->feed());

		$this->assertCount(1, $this->written, 'the entry itself should still be read');
		$this->assertStringNotContainsString('root:', $this->written[0]->getTitle());
	}

	/** Nothing a feed says may become a `javascript:` link on this page. */
	public function testALinkThatIsNotAWebAddressIsDropped(): void {
		$this->answers(<<<'XML'
			<?xml version="1.0"?>
			<feed xmlns="http://www.w3.org/2005/Atom">
				<entry>
					<title>Press me</title>
					<link rel="alternate" href="javascript:alert(1)"/>
					<media:thumbnail xmlns:media="http://search.yahoo.com/mrss/" url="javascript:alert(2)"/>
				</entry>
			</feed>
			XML);

		$this->service->poll($this->feed());

		$this->assertSame('', $this->written[0]->getLink());
		$this->assertSame('', $this->written[0]->getThumbnail());
	}

	/** An entry that names nothing to open and says nothing is not an entry. */
	public function testAnEmptyEntryIsSkipped(): void {
		$this->answers(<<<'XML'
			<?xml version="1.0"?>
			<feed xmlns="http://www.w3.org/2005/Atom"><entry/></feed>
			XML);

		$this->assertSame(0, $this->service->poll($this->feed()));
	}

	public function testAtMostOneHundredEntriesComeOutOfOneDocument(): void {
		$entries = '';
		for ($i = 0; $i < 150; $i++) {
			$entries .= '<entry><title>One ' . $i . '</title><link href="https://example.org/' . $i . '"/></entry>';
		}
		$this->answers('<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom">' . $entries . '</feed>');

		$this->assertSame(SubscriptionService::MAX_ITEMS, $this->service->poll($this->feed()));
	}

	// parseTakeout()

	/**
	 * Takeout writes the header in the language of whoever asked, so it is
	 * recognised by having no channel id in its first field rather than by its
	 * words.
	 */
	public function testATakeoutExportGivesUpItsChannels(): void {
		$channels = SubscriptionService::parseTakeout(
			"Kanal-ID,Kanal-URL,Kanaltitel\n"
			. "UCXuqSBlHAE6Xw-yeJA0Tunw,http://www.youtube.com/channel/UCXuqSBlHAE6Xw-yeJA0Tunw,Linus Tech Tips\n"
			. "UCBa659QWEk1AI4Tg--mrJ2A,http://www.youtube.com/channel/UCBa659QWEk1AI4Tg--mrJ2A,Tom Scott\n"
		);

		$this->assertSame([
			'UCXuqSBlHAE6Xw-yeJA0Tunw' => 'Linus Tech Tips',
			'UCBa659QWEk1AI4Tg--mrJ2A' => 'Tom Scott',
		], $channels);
	}

	public function testATitleWithACommaInItSurvives(): void {
		$channels = SubscriptionService::parseTakeout(
			"Channel Id,Channel Url,Channel Title\n"
			. "UCXuqSBlHAE6Xw-yeJA0Tunw,http://www.youtube.com/channel/UCXuqSBlHAE6Xw-yeJA0Tunw,\"Cooking, slowly\"\n"
		);

		$this->assertSame(['UCXuqSBlHAE6Xw-yeJA0Tunw' => 'Cooking, slowly'], $channels);
	}

	public function testSomethingThatIsNotASubscriptionsExportGivesNothing(): void {
		$this->assertSame([], SubscriptionService::parseTakeout("a,b,c\nd,e,f\n"));
	}

	// subscribe()

	public function testSubscribingFetchesItOnceSoThePageIsNotEmpty(): void {
		$this->answers('<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>A blog</title></feed>');

		$feed = $this->service->subscribe(self::ALICE, 'https://blog.example/feed');

		$this->assertSame('https://blog.example/feed', $feed->getUrl());
		$this->assertSame(Feed::KIND_RSS, $feed->getKind());
		$this->assertSame('A blog', $feed->getTitle());
	}

	public function testAYoutubeSubscriptionIsMarkedAsOne(): void {
		$this->answers('<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"/>');

		$feed = $this->service->subscribe(self::ALICE, 'UCXuqSBlHAE6Xw-yeJA0Tunw');

		$this->assertSame(Feed::KIND_YOUTUBE, $feed->getKind());
	}

	/** The unique index is the authority; two tabs pressing Follow is one follow. */
	public function testSubscribingTwiceSaysSo(): void {
		$this->feedsRequest = $this->createMock(FeedsRequest::class);
		$this->feedsRequest->method('save')->willReturn(false);
		$this->feedsRequest->method('idsOf')->willReturn([]);
		$service = new SubscriptionService(
			$this->feedsRequest, $this->feedItemsRequest, $this->curlService, new NullLogger()
		);

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessageMatches('/already follows/');
		$service->subscribe(self::ALICE, 'https://blog.example/feed');
	}

	public function testAnAccountCannotFollowMoreThanTheCeiling(): void {
		$this->feedsRequest = $this->createMock(FeedsRequest::class);
		$this->feedsRequest->method('idsOf')
			->willReturn(array_fill(0, SubscriptionService::MAX_FEEDS, 1));
		$service = new SubscriptionService(
			$this->feedsRequest, $this->feedItemsRequest, $this->curlService, new NullLogger()
		);

		$this->expectException(InvalidResourceException::class);
		$service->subscribe(self::ALICE, 'https://blog.example/feed');
	}
}
