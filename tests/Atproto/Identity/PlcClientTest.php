<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Identity;

use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Service\CurlService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The PLC directory client: where each call goes, that it goes through
 * the guarded HTTP client with local addresses refused unless the
 * directory is a plain-http one of a test network, and what an answer
 * means: a submission is retried on a network failure or a 5xx and never
 * on the directory's 4xx verdict; a 404 is "not known", anything else not
 * JSON is a failure.
 */
#[AllowMockObjectsWithoutExpectations]
class PlcClientTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	/** @var list<array{method: string, url: string, options: array}> */
	private array $requests = [];
	/** @var list<array{0: int, 1: string}|\Throwable> what the directory answers, in turn */
	private array $answers = [];
	private string $directory = 'https://plc.directory';

	private function client(): PlcClient {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('plcDirectory')->willReturnCallback(fn (): string => $this->directory);
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(function (string $method, string $url, array $options, ?string &$contentType = null, ?int &$status = null): string {
			$this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
			$answer = array_shift($this->answers) ?? [200, '{}'];
			if ($answer instanceof \Throwable) {
				throw $answer;
			}
			$status = $answer[0];

			return $answer[1];
		});

		return new PlcClient($config, $curl, new NullLogger());
	}

	public function testReadsGoToTheDidItsDataAndItsAuditLog(): void {
		$this->answers = [[200, '{"id":"' . self::DID . '"}'], [200, '{"rotationKeys":[]}'], [200, '[{"cid":"bafy"}]']];
		$client = $this->client();

		$this->assertSame(['id' => self::DID], $client->document(self::DID));
		$this->assertSame(['rotationKeys' => []], $client->data(self::DID));
		$this->assertSame([['cid' => 'bafy']], $client->auditLog(self::DID));
		$this->assertSame([
			'https://plc.directory/did%3Aplc%3Aewvi7nxzyoun6zhxrhs64oiz',
			'https://plc.directory/did%3Aplc%3Aewvi7nxzyoun6zhxrhs64oiz/data',
			'https://plc.directory/did%3Aplc%3Aewvi7nxzyoun6zhxrhs64oiz/log/audit',
		], array_column($this->requests, 'url'));
		foreach ($this->requests as $request) {
			$this->assertFalse($request['options']['allow_local_address'], 'the real directory is never a local address');
		}
	}

	public function testADidCannotStepOutOfTheDirectorysPath(): void {
		$this->client()->document('did:plc:../../admin?x=1#');

		$this->assertSame('https://plc.directory/did%3Aplc%3A..%2F..%2Fadmin%3Fx%3D1%23', $this->requests[0]['url']);
	}

	public function testAPlainHttpDirectoryOfATestNetworkMayBeLocal(): void {
		$this->directory = 'http://localhost:2582';
		$this->client()->document(self::DID);

		$this->assertTrue($this->requests[0]['options']['allow_local_address']);
	}

	public function testAnUnknownDidIsNullAndAnythingElseFails(): void {
		$this->answers = [[404, 'not found'], [500, '{"message":"down"}'], [200, 'not json']];
		$client = $this->client();

		$this->assertNull($client->document(self::DID));
		foreach (['PLC directory answered 500: down', 'PLC directory did not answer JSON'] as $expected) {
			try {
				$client->document(self::DID);
				$this->fail('answered');
			} catch (AtprotoException $e) {
				$this->assertSame($expected, $e->getMessage());
			}
		}
	}

	public function testASubmissionIsPostedAsJsonAndRetriedOnlyWhereThatIsHarmless(): void {
		$operation = ['type' => 'plc_operation', 'prev' => null, 'sig' => 'abc', 'services' => ['atproto_pds' => ['endpoint' => 'https://social.example.com']]];
		$this->answers = [new RuntimeException('timeout'), [503, ''], [200, '']];
		$this->client()->submit(self::DID, $operation);

		$this->assertCount(3, $this->requests);
		$this->assertSame('post', $this->requests[2]['method']);
		$this->assertSame('https://plc.directory/did%3Aplc%3Aewvi7nxzyoun6zhxrhs64oiz', $this->requests[2]['url']);
		$this->assertSame($operation, json_decode($this->requests[2]['options']['body'], true));
		$this->assertStringNotContainsString('\/', $this->requests[2]['options']['body'], 'slashes as they are');
	}

	public function testTheDirectorysVerdictIsNotRetried(): void {
		$this->answers = [[400, '{"message":"Invalid signature on op"}']];

		try {
			$this->client()->submit(self::DID, ['sig' => 'x']);
			$this->fail('accepted');
		} catch (AtprotoException $e) {
			$this->assertSame('PLC directory refused the operation (400): Invalid signature on op', $e->getMessage());
		}
		$this->assertCount(1, $this->requests);
	}

	public function testThreeFailuresAreGivenUpOn(): void {
		$this->answers = [[502, ''], [502, ''], new RuntimeException('connection refused'), [200, '']];

		try {
			$this->client()->submit(self::DID, ['sig' => 'x']);
			$this->fail('accepted');
		} catch (AtprotoException $e) {
			$this->assertStringContainsString('connection refused', $e->getMessage());
		}
		$this->assertCount(3, $this->requests);
	}
}
