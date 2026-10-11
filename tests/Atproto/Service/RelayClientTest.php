<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Service;

use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Service\RelayClient;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Service\CurlService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * `requestCrawl` (§7.2): every configured relay is asked, with the
 * hostname a relay expects (bare for https, with its port, with the
 * scheme spelt out for a plain-http development instance), each answer is
 * reported on its own, and failing all of them is an error.
 */
#[AllowMockObjectsWithoutExpectations]
class RelayClientTest extends TestCase {
	/** @var list<array{url: string, options: array}> */
	private array $requests = [];

	/**
	 * @param list<array{0: int, 1: string}|\Throwable> $answers
	 */
	private function relays(string $endpoint, bool $secure, array $relays, array $answers): RelayClient {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('pdsEndpoint')->willReturn($endpoint);
		$config->method('isSecure')->willReturn($secure);
		$config->method('relays')->willReturn($relays);
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(function (string $method, string $url, array $options, ?string &$contentType = null, ?int &$status = null) use (&$answers): string {
			$this->requests[] = ['url' => $url, 'options' => $options];
			$answer = array_shift($answers);
			if ($answer instanceof \Throwable) {
				throw $answer;
			}
			$status = $answer[0];

			return $answer[1];
		});

		return new RelayClient($config, $curl, new NullLogger());
	}

	public function testEveryRelayIsAskedAndEachAnswerReported(): void {
		$client = $this->relays('https://social.example.com', true, ['https://bsky.network', 'https://relay.example.org', 'http://localhost:2470'], [
			[200, '{}'],
			[400, '{"error":"InvalidRequest","message":"hostname is banned"}'],
			new RuntimeException('connection refused'),
		]);

		$this->assertSame([
			'https://bsky.network' => 'ok',
			'https://relay.example.org' => 'refused (400): hostname is banned',
			'http://localhost:2470' => 'not reached: connection refused',
		], $client->requestCrawl());
		$this->assertSame('https://bsky.network/xrpc/com.atproto.sync.requestCrawl', $this->requests[0]['url']);
		$this->assertSame(['hostname' => 'social.example.com'], json_decode($this->requests[0]['options']['body'], true));
		$this->assertFalse($this->requests[0]['options']['allow_local_address']);
		$this->assertTrue($this->requests[2]['options']['allow_local_address'], 'a relay of a test network on this machine');
	}

	public function testTheHostnameCarriesItsPortAndAPlainHttpInstanceSaysSo(): void {
		$this->relays('http://social.test:8080', false, ['https://bsky.network'], [[200, '']])->requestCrawl();

		$this->assertSame(['hostname' => 'http://social.test:8080'], json_decode($this->requests[0]['options']['body'], true));
	}

	public function testNoRelayAcceptingIsAnError(): void {
		$this->expectException(AtprotoException::class);
		$this->relays('https://social.example.com', true, ['https://bsky.network'], [[500, 'oops']])->requestCrawlOrFail();
	}
}
