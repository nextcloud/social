<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use GuzzleHttp\Psr7\Request;
use OCA\Social\Security\RemoteAddress;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\SafeWebPushClient;
use OCA\Social\Service\WebPushNetworkException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SafeWebPushClientTest extends TestCase {
	/** @var CurlService&MockObject */
	private CurlService $curl;

	protected function setUp(): void {
		RemoteAddress::resolveWith(static fn (): array => ['1.1.1.1']);
		$this->curl = $this->createMock(CurlService::class);
	}

	protected function tearDown(): void {
		RemoteAddress::resolveWith(null);
	}

	public function testUsesPinnedRedirectFreeCurlPathAndReturnsProviderResponse(): void {
		$this->curl->expects(self::once())->method('doRequest')->with(
			'post',
			'https://push.example.net/endpoint',
			self::callback(static function (array $options): bool {
				self::assertSame('encrypted-payload', $options['body']);
				self::assertSame(0, $options['max_redirects']);
				self::assertFalse($options['federation']);
				self::assertFalse($options['json_headers']);
				self::assertSame('secret-auth', $options['headers']['Authorization']);

				return true;
			})
		)->willReturnCallback(static function (string $method, string $url, array $options, ?string &$contentType, ?int &$statusCode): string {
			$contentType = 'application/json';
			$statusCode = 201;

			return '{"accepted":true}';
		});

		$request = new Request('POST', 'https://push.example.net/endpoint', ['Authorization' => 'secret-auth'], 'encrypted-payload');
		$response = (new SafeWebPushClient($this->curl))->sendRequest($request);

		$this->assertSame(201, $response->getStatusCode());
		$this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
		$this->assertSame('{"accepted":true}', (string)$response->getBody());
	}

	public function testRefusesInsecureTargetsBeforeConnecting(): void {
		$this->curl->expects(self::never())->method('doRequest');
		$this->expectException(WebPushNetworkException::class);

		(new SafeWebPushClient($this->curl))->sendRequest(new Request('POST', 'http://push.example.net/endpoint'));
	}
}
