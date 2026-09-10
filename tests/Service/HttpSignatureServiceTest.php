<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\HttpSignatureService;
use OCA\Social\Tools\Model\NCRequest;
use OCA\Social\Tools\Model\Request;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Signing outbound fetches. Unsigned, a peer running Mastodon's
 * AUTHORIZED_FETCH or GoToSocial's secure mode answers 401 to every actor,
 * object, collection and outbox GET this app makes.
 */
class HttpSignatureServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example.com/apps/social/@alice';
	private const BOB = 'https://cloud.example.com/apps/social/@bob';

	private static string $privateKey;
	private static string $publicKey;

	/** @var ActorsRequest&MockObject */
	private $actorsRequest;

	public static function setUpBeforeClass(): void {
		$res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($res, $private);
		self::$privateKey = $private;
		self::$publicKey = openssl_pkey_get_details($res)['key'];
	}

	protected function setUp(): void {
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
	}

	private function actor(string $id, int $nid, string $privateKey): Person {
		$actor = new Person();
		$actor->setId($id);
		$actor->setNid($nid);
		$actor->setPrivateKey($privateKey);

		return $actor;
	}

	private function service(): HttpSignatureService {
		return new HttpSignatureService($this->actorsRequest, new NullLogger());
	}

	private function fetch(string $path = '/users/bob', string $host = 'remote.example'): NCRequest {
		$request = new NCRequest($path, Request::TYPE_GET);
		$request->setHost($host);
		$request->setProtocol('https');
		$request->addHeader('Accept', 'application/activity+json');

		return $request;
	}

	/** @return array<string, string> */
	private function signatureParts(NCRequest $request): array {
		$parts = [];
		foreach (explode(',', $request->getHeaders()['Signature']) as $entry) {
			[$k, $v] = explode('=', $entry, 2);
			$parts[$k] = trim($v, '"');
		}

		return $parts;
	}

	public function testAFetchIsSignedWithALocalActorsKey(): void {
		$this->actorsRequest->method('getAll')
			->willReturn([$this->actor(self::ALICE, 1, self::$privateKey)]);
		$request = $this->fetch();

		$this->assertTrue($this->service()->signFetch($request));

		$parts = $this->signatureParts($request);
		$this->assertSame(self::ALICE . '#main-key', $parts['keyId']);
		$this->assertSame('rsa-sha256', $parts['algorithm']);
		$this->assertSame('(request-target) host date', $parts['headers']);
	}

	/**
	 * A GET has no body: signing a digest or a content-length over nothing is
	 * something a peer is entitled to find strange.
	 */
	public function testAFetchSignsNeitherDigestNorContentLength(): void {
		$this->actorsRequest->method('getAll')
			->willReturn([$this->actor(self::ALICE, 1, self::$privateKey)]);
		$request = $this->fetch();

		$this->service()->signFetch($request);

		$headers = $request->getHeaders();
		$this->assertArrayNotHasKey('digest', $headers);
		$this->assertArrayNotHasKey('content-length', $headers);
		$this->assertSame('remote.example', $headers['host']);
		$this->assertNotEmpty($headers['date']);
	}

	public function testTheSignedStringIsTheRequestAsItGoesOut(): void {
		$this->actorsRequest->method('getAll')
			->willReturn([$this->actor(self::ALICE, 1, self::$privateKey)]);
		$request = $this->fetch('/users/bob/outbox');
		$request->addParam('page', '2');

		$this->service()->signFetch($request);

		$headers = $request->getHeaders();
		$expected = implode("\n", [
			'(request-target): get /users/bob/outbox?page=2',
			'host: remote.example',
			'date: ' . $headers['date'],
		]);

		$this->assertSame(
			1,
			openssl_verify(
				$expected,
				base64_decode($this->signatureParts($request)['signature']),
				self::$publicKey,
				OPENSSL_ALGO_SHA256
			)
		);
	}

	/**
	 * Signing as whoever triggered the fetch would tell the remote instance
	 * which of our accounts reads which of their posts. One fixed identity does
	 * not.
	 */
	public function testEveryFetchIsSignedByTheSameActor(): void {
		$this->actorsRequest->method('getAll')->willReturn([
			$this->actor(self::BOB, 7, self::$privateKey),
			$this->actor(self::ALICE, 2, self::$privateKey),
		]);
		$service = $this->service();

		$first = $this->fetch();
		$second = $this->fetch('/users/carol');
		$service->signFetch($first);
		$service->signFetch($second);

		$this->assertSame(self::ALICE . '#main-key', $this->signatureParts($first)['keyId']);
		$this->assertSame(self::ALICE . '#main-key', $this->signatureParts($second)['keyId']);
	}

	public function testTheSigningActorIsReadOnlyOnce(): void {
		$this->actorsRequest->expects($this->once())
			->method('getAll')
			->willReturn([$this->actor(self::ALICE, 1, self::$privateKey)]);
		$service = $this->service();

		$service->signFetch($this->fetch());
		$service->signFetch($this->fetch('/users/carol'));
	}

	/**
	 * Before any local account exists there is no key to sign with. The fetch
	 * still goes out — unsigned is what it was until now, and against a peer
	 * that does not demand a signature it still works.
	 */
	public function testWithoutALocalActorTheFetchGoesOutUnsigned(): void {
		$this->actorsRequest->method('getAll')->willReturn([]);
		$request = $this->fetch();

		$this->assertFalse($this->service()->signFetch($request));
		$this->assertArrayNotHasKey('Signature', $request->getHeaders());
	}

	public function testAnActorWithoutAPrivateKeyIsNotUsedToSign(): void {
		$this->actorsRequest->method('getAll')->willReturn([
			$this->actor(self::ALICE, 1, ''),
			$this->actor(self::BOB, 2, self::$privateKey),
		]);
		$request = $this->fetch();

		$this->assertTrue($this->service()->signFetch($request));
		$this->assertSame(self::BOB . '#main-key', $this->signatureParts($request)['keyId']);
	}

	public function testAnUnusableKeyLeavesTheRequestUnsigned(): void {
		$this->actorsRequest->method('getAll')
			->willReturn([$this->actor(self::ALICE, 1, 'not a key')]);
		$request = $this->fetch();

		$this->assertFalse($this->service()->signFetch($request));
		$this->assertArrayNotHasKey('Signature', $request->getHeaders());
	}
}
