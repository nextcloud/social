<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use OCA\Social\Atproto\Move\PdsClient;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Service\CurlService;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PdsClientTest extends TestCase {
	private function client(bool $local): PdsClient {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->with('allow_local_remote_servers', false)->willReturn($local);

		return new PdsClient($this->createMock(CurlService::class), $config);
	}

	public function testAPdsIsItsHttpsOrigin(): void {
		$client = $this->client(false);

		$this->assertSame('https://pds.example.com', $client->origin('pds.example.com'));
		$this->assertSame('https://pds.example.com:8443', $client->origin('https://pds.example.com:8443/xrpc/'));
		foreach (['http://pds.example.com', 'https://user:pw@pds.example.com', 'ftp://pds.example.com', ''] as $typed) {
			try {
				$client->origin($typed);
				$this->fail('taken: ' . $typed);
			} catch (AtprotoException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testAnEmptyInputIsSentAsAnObject(): void {
		$sent = [];
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(function (string $method, string $url, array $options, &$contentType = null, &$status = null) use (&$sent): string {
			$sent[] = [$method, $options['body']];
			$status = 200;

			return '{}';
		});
		$client = new PdsClient($curl, $this->createMock(IConfig::class));

		$client->call('https://pds.example.com', 'com.atproto.server.deactivateAccount', 'POST', [], []);
		$client->call('https://pds.example.com', 'com.atproto.repo.createRecord', 'POST', [], ['repo' => 'did:plc:a']);

		$this->assertSame([['post', '{}'], ['post', '{"repo":"did:plc:a"}']], $sent);
	}

	/** Every call goes out through the app's own client, which guards and pins the address. */
	public function testACallIsMadeThroughCurlServiceAndAnswersStatusAndBody(): void {
		$seen = [];
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(function (string $method, string $url, array $options, &$contentType = null, &$status = null) use (&$seen): string {
			$seen = ['method' => $method, 'url' => $url, 'options' => $options];
			$status = 400;

			return '{"error":"InvalidRequest","message":"no"}';
		});
		$client = new PdsClient($curl, $this->createMock(IConfig::class));

		$answer = $client->call('https://pds.example.com', 'com.atproto.server.describeServer', 'GET', ['a' => 'b'], null, null, '', 'tok');

		$this->assertSame(400, $answer['status']);
		$this->assertSame('InvalidRequest', $answer['body']['error']);
		$this->assertSame('get', $seen['method']);
		$this->assertSame('https://pds.example.com/xrpc/com.atproto.server.describeServer?a=b', $seen['url']);
		$this->assertSame('Bearer tok', $seen['options']['headers']['Authorization']);
		$this->assertTrue($seen['options']['accept_errors'], 'the status is the caller\'s to judge');
		$this->assertFalse($seen['options']['json_headers'], 'no ActivityPub negotiation on an XRPC call');
	}

	/**
	 * A JSON call is waited for in a request, so it is given twenty seconds;
	 * only a repository or a blob, which move in a background job, get ten
	 * minutes.
	 */
	public function testAJsonCallWaitsTwentySecondsAndABodyUploadTenMinutes(): void {
		$timeouts = [];
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(function (string $method, string $url, array $options, &$contentType = null, &$status = null) use (&$timeouts): string {
			$timeouts[] = $options['timeout'];
			$status = 200;

			return '{}';
		});
		$client = new PdsClient($curl, $this->createMock(IConfig::class));

		$client->call('https://pds.example.com', 'com.atproto.server.describeServer');
		$client->call('https://pds.example.com', 'com.atproto.server.createAccount', 'POST', [], ['did' => 'did:plc:a']);
		$client->call('https://pds.example.com', 'com.atproto.repo.importRepo', 'POST', [], null, 'CAR', 'application/vnd.ipld.car');

		$this->assertSame([20, 20, 600], $timeouts);
		$this->assertSame(20, PdsClient::TIMEOUT);
	}

	public function testAServerThatCannotBeReachedIsAnAtprotoException(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willThrowException(new RequestNetworkException('refused'));
		$client = new PdsClient($curl, $this->createMock(IConfig::class));

		$this->expectException(AtprotoException::class);
		$client->call('https://pds.example.com', 'com.atproto.server.describeServer');
	}

	public function testBytesAreReadAsAStreamWithTheirType(): void {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, 'CAR');
		rewind($stream);
		$curl = $this->createMock(CurlService::class);
		$curl->method('openStream')->willReturnCallback(function (string $url, array $headers, string $onBehalfOf, int $timeout) use ($stream): array {
			$this->assertSame('https://pds.example.com/xrpc/com.atproto.sync.getRepo?did=did%3Aplc%3Aa', $url);
			$this->assertSame('Bearer tok', $headers['Authorization']);
			$this->assertSame(600, $timeout);

			return ['stream' => $stream, 'status' => 200, 'headers' => ['Content-Type' => ['application/vnd.ipld.car']]];
		});
		$client = new PdsClient($curl, $this->createMock(IConfig::class));

		$this->assertSame(
			['status' => 200, 'bytes' => 'CAR', 'type' => 'application/vnd.ipld.car'],
			$client->bytes('https://pds.example.com', 'com.atproto.sync.getRepo', ['did' => 'did:plc:a'], 'tok')
		);
	}

	public function testARefusedByteAnswerCarriesItsStatus(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->method('openStream')->willThrowException(new RequestContentException('url', 404));
		$client = new PdsClient($curl, $this->createMock(IConfig::class));

		$this->assertSame(['status' => 404, 'bytes' => '', 'type' => ''], $client->bytes('https://pds.example.com', 'com.atproto.sync.getBlob', ['cid' => 'x']));
	}

	public function testPlainHttpOnlyOnAServerThatTalksToLocalOnes(): void {
		$this->assertSame('http://localhost:2583', $this->client(true)->origin('http://localhost:2583'));
	}
}
