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
}
