<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\FeedsRequest;
use OCA\Social\Service\FeedDiscoveryService;
use OCA\Social\Service\FeedParserService;
use OCA\Social\Service\SubscriptionService;
use OCA\Social\Tests\Helper\EndlessStream;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IPromise;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SubscriptionServiceTest extends TestCase {
	private const FEED = '<?xml version="1.0"?><rss version="2.0"><channel><title>Blog</title>'
		. '<link>https://blog.example/</link><item><guid>1</guid><title>One</title>'
		. '<link>https://blog.example/1</link></item></channel></rss>';

	private FeedsRequest|MockObject $feedsRequest;
	private FeedDiscoveryService|MockObject $discovery;
	private IClient|MockObject $client;
	private SubscriptionService $service;
	/** @var array<array<string, mixed>> the options of every request made */
	private array $requests = [];

	protected function setUp(): void {
		$this->feedsRequest = $this->createMock(FeedsRequest::class);
		$this->discovery = $this->createMock(FeedDiscoveryService::class);
		$this->client = $this->createMock(IClient::class);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);

		$this->service = new SubscriptionService(
			$this->feedsRequest, $this->discovery, new FeedParserService(), $clientService, new NullLogger()
		);
	}

	/** @param string|resource $body */
	private function answers($body): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);
		$response->method('getHeader')->willReturn('');
		$this->client->method('get')->willReturnCallback(function (string $url, array $options) use ($response): IResponse {
			$this->requests[] = $options;

			return $response;
		});
	}

	private function feed(): array {
		return ['id' => 7, 'url' => 'https://blog.example/feed', 'etag' => '', 'modified_at' => ''];
	}

	public function testAFeedIsReadAndItsEntriesStored(): void {
		$this->answers(self::FEED);
		$this->feedsRequest->expects($this->once())->method('addItem')->willReturn(true);
		$this->feedsRequest->expects($this->once())->method('recordRead')
			->with(7, 'Blog', 'https://blog.example/', '', '', '');

		$this->assertSame(1, $this->service->refresh($this->feed()));
		$this->assertTrue($this->requests[0]['stream'] ?? false, 'the body is streamed, so the ceiling bounds memory');
	}

	/**
	 * A feed that answers with more than the ceiling is refused having read
	 * one byte past it — not buffered whole and then cut, which for a body
	 * of hundreds of megabytes is a memory-limit fatal that ends the cron run
	 * and leaves the feed first in line for the next one.
	 */
	public function testAFeedLargerThanTheCeilingIsRefusedWithoutReadingItAll(): void {
		$this->answers(EndlessStream::open());
		$this->feedsRequest->expects($this->never())->method('addItem');
		$this->feedsRequest->expects($this->once())->method('recordRead')
			->with(7, '', '', '', '', 'too large to read');

		$this->assertSame(0, $this->service->refresh($this->feed()));
		$this->assertLessThan(5 * 1024 * 1024, EndlessStream::$read);
	}

	/**
	 * Following answers once the row is stored: the feed is read by the cron,
	 * not inside the request.
	 */
	public function testFollowingStoresTheFeedWithoutReadingIt(): void {
		$this->discovery->method('discover')->willReturn('https://blog.example/feed');
		$this->feedsRequest->method('feedsOf')->willReturn([]);
		$this->feedsRequest->method('idOf')->willReturn(0);
		$this->feedsRequest->expects($this->once())->method('create')
			->with('alice', 'https://blog.example/feed', '', '')->willReturn(9);
		$this->client->expects($this->never())->method('get');
		$this->feedsRequest->expects($this->never())->method('addItem');

		$this->assertSame(['id' => 9, 'url' => 'https://blog.example/feed'], $this->service->follow('alice', 'https://blog.example/'));
	}

	/**
	 * A takeout is two hundred channels at most; each becomes a row and none
	 * is fetched in the upload request.
	 */
	public function testATakeoutStoresEveryChannelAndFetchesNothing(): void {
		$csv = "Channel Id,Channel Url,Channel Title\n";
		for ($i = 0; $i < 3; $i++) {
			$csv .= 'UC' . str_repeat((string)$i, 22) . ",https://www.youtube.com/channel/x,Channel $i\n";
		}
		$this->discovery->method('discover')->willReturnCallback(
			fn (string $url): string => 'https://www.youtube.com/feeds/videos.xml?channel_id=' . substr($url, strrpos($url, '/') + 1)
		);
		$this->feedsRequest->method('feedsOf')->willReturn([]);
		$this->feedsRequest->method('idOf')->willReturn(0);
		$this->feedsRequest->expects($this->exactly(3))->method('create');
		$this->client->expects($this->never())->method('get');

		$this->assertSame(3, $this->service->importTakeout('alice', $csv));
	}

	public function testATakeoutStopsAtTheFeedAllowance(): void {
		$csv = '';
		for ($i = 0; $i < 5; $i++) {
			$csv .= 'UC' . str_repeat((string)$i, 22) . "\n";
		}
		$this->discovery->method('discover')->willReturnArgument(0);
		$this->feedsRequest->method('feedsOf')->willReturn(array_fill(0, SubscriptionService::MAX_FEEDS - 2, ['id' => 1]));
		$this->feedsRequest->method('idOf')->willReturn(0);
		$this->feedsRequest->expects($this->exactly(2))->method('create');

		$this->assertSame(2, $this->service->importTakeout('alice', $csv));
	}

	public function testAChannelAlreadyFollowedIsNotCountedAgain(): void {
		$this->discovery->method('discover')->willReturnArgument(0);
		$this->feedsRequest->method('feedsOf')->willReturn([]);
		$this->feedsRequest->method('idOf')->willReturn(4);
		$this->feedsRequest->expects($this->never())->method('create');

		$this->assertSame(0, $this->service->importTakeout('alice', 'UC' . str_repeat('a', 22) . "\n"));
	}

	/**
	 * Nothing in a feed means one thing before its first read and another
	 * after, and the page has no other way to tell them apart.
	 */
	public function testTheListSaysWhetherAFeedHasBeenReadYet(): void {
		$this->feedsRequest->method('feedsOf')->willReturn([
			['id' => 1, 'url' => 'https://blog.example/feed', 'title' => 'Blog', 'site_url' => '', 'error' => '', 'fetched_at' => '2026-09-01 08:00:00'],
			['id' => 2, 'url' => 'https://other.example/feed', 'title' => '', 'site_url' => '', 'error' => '', 'fetched_at' => null],
		]);
		$this->feedsRequest->method('countsFor')->willReturn([1 => 0]);

		$feeds = $this->service->feeds('alice');

		$this->assertTrue($feeds[0]['read']);
		$this->assertSame(0, $feeds[0]['items']);
		$this->assertFalse($feeds[1]['read']);
	}

	/** A read that added something prunes the feed to its allowance. */
	public function testAReadPrunesToTheAllowance(): void {
		$this->answers(self::FEED);
		$this->feedsRequest->method('addItem')->willReturn(true);
		$this->feedsRequest->expects($this->once())->method('prune')
			->with(7, SubscriptionService::KEEP_ITEMS, $this->callback(
				fn (int $before): bool => abs($before - (time() - SubscriptionService::KEEP_DAYS * 86400)) <= 2
			));

		$this->assertSame(1, $this->service->refresh($this->feed()));
	}

	/**
	 * Age decides nothing here. A blog whose last post is years old is still
	 * a blog somebody chose to follow: it used to read cleanly, parse, and
	 * store none of its entries, leaving a subscription that said "0 entries"
	 * with no error against it. The prune bounds a feed, not this.
	 */
	public function testAFeedThatStoppedPostingYearsAgoIsStillStored(): void {
		$old = gmdate(DATE_RSS, time() - (SubscriptionService::KEEP_DAYS + 10) * 86400);
		$this->answers('<?xml version="1.0"?><rss version="2.0"><channel><title>Blog</title>'
			. '<item><guid>old</guid><title>Old</title><link>https://blog.example/old</link><pubDate>' . $old . '</pubDate></item>'
			. '</channel></rss>');

		$this->feedsRequest->expects($this->once())->method('addItem')
			->with(7, $this->callback(fn (array $item): bool => $item['guid'] === 'old'))
			->willReturn(true);

		$this->assertSame(1, $this->service->refresh($this->feed()));
	}

	public function testAReadThatAddsNothingDoesNotPrune(): void {
		$this->answers(self::FEED);
		$this->feedsRequest->method('addItem')->willReturn(false);
		$this->feedsRequest->expects($this->never())->method('prune');

		$this->assertSame(0, $this->service->refresh($this->feed()));
	}

	/** @param array<array<string, mixed>> $feeds */
	private function feeds(int $from, int $count): array {
		$feeds = [];
		for ($i = $from; $i < $from + $count; $i++) {
			$feeds[] = ['id' => $i, 'url' => 'https://blog.example/feed' . $i, 'etag' => '', 'modified_at' => ''];
		}

		return $feeds;
	}

	/**
	 * A batch is sent before any of it is waited for: the feeds are read
	 * together, and one slow server holds up its batch rather than the pass.
	 */
	public function testADueBatchIsReadConcurrently(): void {
		$events = [];
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(304);
		$this->client->expects($this->never())->method('get');
		$this->client->method('getAsync')->willReturnCallback(function (string $url, array $options) use (&$events, $response): IPromise {
			$events[] = 'send ' . $url;
			$this->assertTrue($options['stream']);
			$promise = $this->createMock(IPromise::class);
			$promise->method('wait')->willReturnCallback(function () use (&$events, $url, $response): IResponse {
				$events[] = 'wait ' . $url;

				return $response;
			});

			return $promise;
		});
		$this->feedsRequest->method('due')->willReturnOnConsecutiveCalls($this->feeds(1, 3), []);
		$this->feedsRequest->expects($this->exactly(3))->method('recordRead');

		$this->service->refreshDue(time() + 60);

		$this->assertSame([
			'send https://blog.example/feed1', 'send https://blog.example/feed2', 'send https://blog.example/feed3',
			'wait https://blog.example/feed1', 'wait https://blog.example/feed2', 'wait https://blog.example/feed3',
		], $events);
	}

	/** The pass goes on to the next batch while it has time, not after twenty. */
	public function testThePassKeepsTakingBatchesUntilNothingIsDue(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(304);
		$promise = $this->createMock(IPromise::class);
		$promise->method('wait')->willReturn($response);
		$this->client->method('getAsync')->willReturn($promise);

		$this->feedsRequest->expects($this->exactly(4))->method('due')
			->with(SubscriptionService::PARALLEL, $this->anything())
			->willReturnOnConsecutiveCalls(
				$this->feeds(1, SubscriptionService::PARALLEL),
				$this->feeds(11, SubscriptionService::PARALLEL),
				$this->feeds(21, SubscriptionService::PARALLEL),
				[]
			);
		$this->feedsRequest->expects($this->exactly(3 * SubscriptionService::PARALLEL))->method('recordRead');

		$this->service->refreshDue(time() + 60);
	}

	public function testAPassWithNoTimeLeftReadsNothing(): void {
		$this->feedsRequest->expects($this->never())->method('due');
		$this->client->expects($this->never())->method('getAsync');

		$this->assertSame(0, $this->service->refreshDue(time() - 1));
	}

	/**
	 * A feed handed out again in the same pass — its read could not be
	 * recorded — is not read again: the pass ends instead of spinning.
	 */
	public function testAFeedIsReadOncePerPass(): void {
		$promise = $this->createMock(IPromise::class);
		$promise->method('wait')->willThrowException(new \RuntimeException('down'));
		$this->client->expects($this->once())->method('getAsync')->willReturn($promise);
		$this->feedsRequest->method('due')->willReturn($this->feeds(1, 1));
		$this->feedsRequest->expects($this->once())->method('recordRead')
			->with(1, '', '', '', '', 'could not be read');

		$this->assertSame(0, $this->service->refreshDue(time() + 60));
	}
}
