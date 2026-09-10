<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use DateTime;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SignatureIsGoneException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\LinkedDataSignature;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\HttpSignatureService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Model\NCRequest;
use OCA\Social\Tools\Model\Request;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SignatureServiceTest extends TestCase {
	private const CLOUD_HOST = 'cloud.example.com';
	private const LOCAL_ACTOR = 'https://cloud.example.com/apps/social/@alice';
	private const REMOTE_ACTOR = 'https://remote.example/users/bob';
	private const REMOTE_KEY_ID = self::REMOTE_ACTOR . '#main-key';

	/** One RSA key pair for the whole class: generating 2048-bit keys per test is slow. */
	private static string $privateKey;
	private static string $publicKey;
	private static string $otherPublicKey;

	private ActorsRequest|MockObject $actorsRequest;
	private CacheActorService|MockObject $cacheActorService;
	private CacheActorsRequest|MockObject $cacheActorsRequest;
	private SignatureService $service;
	/** @var array<string, mixed> backing store of the mocked replay cache */
	private array $seenSignatures = [];
	/** @var array<string, mixed> backing store of the mocked key-attempt cache */
	private array $keyAttempts = [];

	public static function setUpBeforeClass(): void {
		[self::$privateKey, self::$publicKey] = self::keyPair();
		[, self::$otherPublicKey] = self::keyPair();
	}

	/** @return array{string, string} private and public PEM */
	private static function keyPair(): array {
		$res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($res, $private);

		return [$private, openssl_pkey_get_details($res)['key']];
	}

	protected function setUp(): void {
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		// no key is in the local cache unless a test puts one there
		$this->cacheActorsRequest->method('getFromId')
			->willThrowException(new CacheActorDoesNotExistException());

		$configService = $this->createMock(ConfigService::class);
		$configService->method('getCloudHost')->willReturn(self::CLOUD_HOST);
		// the real one narrows the request timeout around the call; here it only
		// has to run what it is given
		$configService->method('withRequestTimeout')
			->willReturnCallback(fn (int $timeout, callable $action) => $action());

		// in-memory stand-ins for the two distributed caches
		$this->seenSignatures = [];
		$this->keyAttempts = [];
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturnCallback(
			fn (string $prefix): ICache => $prefix === 'social.keys'
				? $this->arrayCache($this->keyAttempts)
				: $this->arrayCache($this->seenSignatures)
		);

		$this->service = new SignatureService(
			$this->actorsRequest,
			$this->cacheActorService,
			$this->cacheActorsRequest,
			$this->createMock(CurlService::class),
			$configService,
			new HttpSignatureService($this->actorsRequest, new NullLogger()),
			$cacheFactory,
			new NullLogger(),
		);
	}

	/**
	 * @param array<string, mixed> $store
	 * @return ICache&MockObject
	 */
	private function arrayCache(array &$store): ICache {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			function (string $key) use (&$store) {
				return $store[$key] ?? null;
			}
		);
		$cache->method('set')->willReturnCallback(
			function (string $key, $value) use (&$store) {
				$store[$key] = $value;

				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function (string $key) use (&$store) {
				unset($store[$key]);

				return true;
			}
		);

		return $cache;
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function person(string $id, string $publicKey = '', string $privateKey = ''): Person {
		$person = new Person();
		$person->setId($id);
		$person->setPublicKey($publicKey);
		$person->setPrivateKey($privateKey);

		return $person;
	}

	public function testGenerateKeysGivesTheActorAMatchingRsaPair(): void {
		$actor = new Person();

		$this->service->generateKeys($actor);

		$this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $actor->getPrivateKey());
		$this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $actor->getPublicKey());
		$details = openssl_pkey_get_details(openssl_pkey_get_private($actor->getPrivateKey()));
		$this->assertSame(2048, $details['bits']);
		$this->assertSame(OPENSSL_KEYTYPE_RSA, $details['type']);
		$this->assertSame($actor->getPublicKey(), $details['key'], 'public key belongs to the private key');
	}

	public function testSignRequestAddsDigestDateHostContentLengthAndSignature(): void {
		$request = new NCRequest('/users/bob/inbox', Request::TYPE_POST);
		$request->setData(['type' => 'Create', 'id' => 'https://cloud.example.com/apps/social/@alice/1']);
		$body = $request->getDataBody();
		$queue = new RequestQueue('{}', new InstancePath('https://remote.example/users/bob/inbox', InstancePath::TYPE_INBOX), self::LOCAL_ACTOR);
		$this->actorsRequest->expects($this->once())
			->method('getFromId')
			->with(self::LOCAL_ACTOR)
			->willReturn($this->person(self::LOCAL_ACTOR, self::$publicKey, self::$privateKey));

		$this->service->signRequest($request, $queue);

		$headers = $request->getHeaders();
		$this->assertSame((string)strlen($body), $headers['content-length']);
		$this->assertSame('remote.example', $headers['host']);
		$this->assertSame('SHA-256=' . base64_encode(hash('sha256', $body, true)), $headers['digest']);
		$this->assertNotFalse(DateTime::createFromFormat(SignatureService::DATE_HEADER, $headers['date']));
		$this->assertEqualsWithDelta(time(), (new DateTime($headers['date']))->getTimestamp(), 5);

		$this->assertMatchesRegularExpression(
			'/^keyId="' . preg_quote(self::LOCAL_ACTOR, '/') . '#main-key",algorithm="rsa-sha256",'
			. 'headers="\(request-target\) content-length date host digest",signature="[A-Za-z0-9+\/=]+"$/',
			$headers['Signature'],
		);

		preg_match('/signature="([^"]+)"/', $headers['Signature'], $m);
		$signingString = implode("\n", [
			'(request-target): post /users/bob/inbox',
			'content-length: ' . strlen($body),
			'date: ' . $headers['date'],
			'host: remote.example',
			'digest: ' . $headers['digest'],
		]);
		$this->assertSame(1, openssl_verify($signingString, base64_decode($m[1]), self::$publicKey, OPENSSL_ALGO_SHA256));
	}

	/**
	 * Build the headers of an HTTP-signed inbox POST, the way a remote server would send them.
	 *
	 * @return array<string, string> lower-cased header names
	 */
	private function signedHeaders(
		string $body,
		string $privateKey,
		array $overrides = [],
		string $headerList = '(request-target) host date digest content-length',
		string $algorithm = 'rsa-sha256',
		string $keyId = self::REMOTE_KEY_ID,
	): array {
		$headers = array_merge([
			'date' => gmdate(SignatureService::DATE_HEADER),
			'host' => self::CLOUD_HOST,
			'digest' => 'SHA-256=' . base64_encode(hash('sha256', $body, true)),
			'content-length' => (string)strlen($body),
		], $overrides);

		$lines = [];
		foreach (explode(' ', $headerList) as $key) {
			$lines[] = $key === '(request-target)' ? '(request-target): post /apps/social/@alice/inbox' : $key . ': ' . $headers[$key];
		}
		openssl_sign(implode("\n", $lines), $signed, $privateKey, $algorithm === 'rsa-sha512' ? OPENSSL_ALGO_SHA512 : OPENSSL_ALGO_SHA256);

		$headers['signature'] = sprintf(
			'keyId="%s",algorithm="%s",headers="%s",signature="%s"',
			$keyId,
			$algorithm,
			$headerList,
			base64_encode($signed),
		);

		return $headers;
	}

	private function incomingRequest(array $headers): IRequest|MockObject {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name) => $headers[strtolower($name)] ?? '');
		$request->method('getMethod')->willReturn('POST');
		$request->method('getRequestUri')->willReturn('/apps/social/@alice/inbox');

		return $request;
	}

	public function testCheckRequestAcceptsAValidSignatureAndReturnsTheKeyOrigin(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->expects($this->once())
			->method('getFromId')
			->with(self::REMOTE_ACTOR, false)
			->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$time = 0;
		$origin = $this->service->checkRequest($this->incomingRequest($headers), $body, $time);

		$this->assertSame('remote.example', $origin);
		$this->assertSame((new DateTime($headers['date']))->getTimestamp(), $time);
	}

	public function testCheckRequestAcceptsRsaSha512(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, [], '(request-target) host date digest', 'rsa-sha512');
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function testCheckRequestRejectsATamperedBody(): void {
		$headers = $this->signedHeaders('{"type":"Follow"}', self::$privateKey);
		$tampered = '{"type":"Delete"}';
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('digest does not match the body');
		$this->service->checkRequest($this->incomingRequest($headers), $tampered);
	}

	/**
	 * A sender using chunked transfer encoding sends no Content-Length at all.
	 * Comparing the body against `(int)''` rejected every one of them.
	 */
	public function testCheckRequestAcceptsARequestWithoutContentLength(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders(
			$body, self::$privateKey, ['content-length' => ''], '(request-target) host date digest'
		);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function digestVariantProvider(): array {
		$body = '{"type":"Follow"}';
		$sha256 = base64_encode(hash('sha256', $body, true));
		$sha512 = base64_encode(hash('sha512', $body, true));

		return [
			'RFC 3230, upper case' => ['SHA-256=' . $sha256],
			'lower case algorithm' => ['sha-256=' . $sha256],
			'no hyphen' => ['SHA256=' . $sha256],
			'several algorithms' => ['SHA-256=' . $sha256 . ',SHA-512=' . $sha512],
			'sha-512 only' => ['SHA-512=' . $sha512],
			'an algorithm we do not know, alongside one we do'
				=> ['id-sha-3=deadbeef,SHA-256=' . $sha256],
			'spaces around the list separator' => ['SHA-512=' . $sha512 . ', SHA-256=' . $sha256],
		];
	}

	/**
	 * @dataProvider digestVariantProvider
	 */
	public function testCheckRequestAcceptsEveryDigestFormOnTheWire(string $digest): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, ['digest' => $digest]);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	/** RFC 9530: what newer implementations are moving to. */
	public function testCheckRequestAcceptsAContentDigestOnItsOwn(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders(
			$body,
			self::$privateKey,
			[
				'digest' => '',
				'content-digest' => 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':',
			],
			'(request-target) host date content-digest'
		);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function testCheckRequestRejectsADigestWeCannotCompute(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, ['digest' => 'id-sha-3=deadbeef']);

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('no digest algorithm we can compute');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsAMissingDigest(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, ['digest' => '']);

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('no digest header');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsASecondDigestThatDoesNotMatch(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, [
			'digest' => 'SHA-256=' . base64_encode(hash('sha256', $body, true))
				. ',SHA-512=' . base64_encode(hash('sha512', 'something else', true)),
		]);

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('digest does not match the body');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	/**
	 * hs2019 names no hash: the key type decides, and for the RSA keys actors
	 * publish that is SHA-256.
	 */
	public function testCheckRequestAcceptsHs2019OverAnRsaKey(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders(
			$body, self::$privateKey, [], '(request-target) host date digest', 'hs2019'
		);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	/**
	 * Mapping an unknown algorithm to sha256 made an Ed25519 signature fail as
	 * "signature cannot be checked", which describes nothing.
	 */
	public function testCheckRequestNamesAnAlgorithmItCannotVerify(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders(
			$body, self::$privateKey, [], '(request-target) host date digest', 'ed25519'
		);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('unsupported signature algorithm: ed25519');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsAWrongContentLength(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, ['content-length' => '3']);

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('content-length');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsAnExpiredDate(): void {
		$body = '{"type":"Follow"}';
		$expired = gmdate(SignatureService::DATE_HEADER, time() - SignatureService::DATE_DELAY - 30);
		$headers = $this->signedHeaders($body, self::$privateKey, ['date' => $expired]);

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('too old');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsAFutureDate(): void {
		// without the upper bound, a request stamped into the future would stay
		// replayable until that date finally became "too old"
		$body = '{"type":"Follow"}';
		$future = gmdate(SignatureService::DATE_HEADER, time() + SignatureService::DATE_DELAY + 30);
		$headers = $this->signedHeaders($body, self::$privateKey, ['date' => $future]);

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('from the future');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsAMissingDate(): void {
		// an absent Date would parse as "now" and never age out
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$headers['date'] = '';

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('missing date');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsAnUnparsableDate(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, ['date' => 'not a date']);

		$this->expectException(DateTimeException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsAMissingSignatureHeader(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		unset($headers['signature']);

		$this->expectException(MalformedArrayException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRefreshesTheKeyOnceThenRefusesABadSignature(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->expects($this->exactly(2))
			->method('getFromId')
			->withConsecutive([self::REMOTE_ACTOR, false], [self::REMOTE_ACTOR, true])
			->willReturn($this->person(self::REMOTE_ACTOR, self::$otherPublicKey));

		// A signature that does not verify against either the cached or the refreshed
		// key is refused here, rather than being returned as an empty origin for a
		// later check to reject.
		$this->expectException(SignatureException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestAcceptsAfterRefreshingAStaleCachedKey(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->expects($this->exactly(2))
			->method('getFromId')
			->willReturnOnConsecutiveCalls(
				$this->person(self::REMOTE_ACTOR, self::$otherPublicKey),
				$this->person(self::REMOTE_ACTOR, self::$publicKey),
			);

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function testCheckRequestWithAnUnknownActorIsRefused(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->method('getFromId')->willThrowException(new RequestContentException('not found', 404));

		// The signing key cannot be fetched, so the request cannot be verified and is
		// refused here rather than proceeding with an empty origin.
		$this->expectException(SignatureException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestSignalsAGoneActor(): void {
		$body = '{"type":"Delete"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->method('getFromId')->willThrowException(new RequestContentException('gone', 410));

		$this->expectException(SignatureIsGoneException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	/**
	 * @dataProvider incompleteSignedHeaderSets
	 */
	public function testCheckRequestRefusesASignatureThatDoesNotCoverEveryMandatoryHeader(string $headerList): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, [], $headerList);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		// (request-target), host, date and digest must all be in the signed set, so the
		// signature binds the body and cannot be replayed elsewhere.
		$this->expectException(SignatureException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function incompleteSignedHeaderSets(): array {
		return [
			'missing (request-target)' => ['host date digest'],
			'missing host' => ['(request-target) date digest'],
			'missing date' => ['(request-target) host digest'],
			'missing digest' => ['(request-target) host date'],
		];
	}

	public function testCheckRequestRejectsAKeyIdWithoutHost(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, [], '(request-target) host date digest', 'rsa-sha256', 'main-key');

		$this->expectException(InvalidOriginException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestAcceptsAFullySignedRequestFromAKnownActor(): void {
		// Guard proving the tightened checks did not break legitimate federation: a
		// request that signs every mandatory header — (request-target), host, date and
		// digest — plus content-length with a key that verifies is still accepted and
		// yields the key's origin.
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders(
			$body,
			self::$privateKey,
			[],
			'(request-target) host date digest content-length',
		);
		$this->cacheActorService->expects($this->once())
			->method('getFromId')
			->with(self::REMOTE_ACTOR, false)
			->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$time = 0;
		$origin = $this->service->checkRequest($this->incomingRequest($headers), $body, $time);

		$this->assertSame('remote.example', $origin);
		$this->assertSame((new DateTime($headers['date']))->getTimestamp(), $time);
	}

	// bounding the pre-authentication key fetch

	public function testAKeyAlreadyInTheLocalCacheIsNeverFetched(): void {
		// the whole point: a known peer's delivery costs a database read
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$cached = $this->createMock(CacheActorsRequest::class);
		$cached->method('getFromId')->with(self::REMOTE_ACTOR)
			->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));
		$this->replaceLocalActorCache($cached);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function testTheFetchOfAnUnknownKeyIsTimeBounded(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$seen = [];
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getCloudHost')->willReturn(self::CLOUD_HOST);
		$configService->method('withRequestTimeout')->willReturnCallback(
			function (int $timeout, callable $action) use (&$seen) {
				$seen[] = $timeout;

				return $action();
			}
		);
		$this->rebuildWith($configService);
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->service->checkRequest($this->incomingRequest($headers), $body);

		$this->assertSame([SignatureService::UNKNOWN_KEY_TIMEOUT], $seen);
	}

	public function testAKeyThatCannotBeResolvedIsNotFetchedAgainImmediately(): void {
		// a flood naming the same unresolvable keyId costs one fetch, not one
		// fetch per request
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->expects($this->once())->method('getFromId')
			->willThrowException(new RequestContentException('unreachable'));

		try {
			$this->service->checkRequest($this->incomingRequest($headers), $body);
			$this->fail('expected the first attempt to fail');
		} catch (\Exception $e) {
		}

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('too recently');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testAForcedRefreshIsThrottledPerKeyId(): void {
		// verification failing is free to trigger from outside, and each failure
		// used to force another fetch of the same key
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		// known locally, but with a key the signature does not verify against
		$cached = $this->createMock(CacheActorsRequest::class);
		$cached->method('getFromId')
			->willReturn($this->person(self::REMOTE_ACTOR, self::$otherPublicKey));
		$this->replaceLocalActorCache($cached);
		$this->cacheActorService->expects($this->once())->method('getFromId')
			->with(self::REMOTE_ACTOR, true)
			->willReturn($this->person(self::REMOTE_ACTOR, self::$otherPublicKey));

		try {
			$this->service->checkRequest($this->incomingRequest($headers), $body);
			$this->fail('expected the signature not to verify');
		} catch (SignatureException $e) {
		}

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('too recently');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	/** Rebuilds the service around a different local actor cache. */
	private function replaceLocalActorCache(CacheActorsRequest $cache): void {
		$this->cacheActorsRequest = $cache;
		$this->rebuildWith(null);
	}

	private function rebuildWith(?ConfigService $configService): void {
		if ($configService === null) {
			$configService = $this->createMock(ConfigService::class);
			$configService->method('getCloudHost')->willReturn(self::CLOUD_HOST);
			$configService->method('withRequestTimeout')
				->willReturnCallback(fn (int $timeout, callable $action) => $action());
		}

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturnCallback(
			fn (string $prefix): ICache => $prefix === 'social.keys'
				? $this->arrayCache($this->keyAttempts)
				: $this->arrayCache($this->seenSignatures)
		);

		$this->service = new SignatureService(
			$this->actorsRequest,
			$this->cacheActorService,
			$this->cacheActorsRequest,
			$this->createMock(CurlService::class),
			$configService,
			new HttpSignatureService($this->actorsRequest, new NullLogger()),
			$cacheFactory,
			new NullLogger(),
		);
	}

	/**
	 * @dataProvider allowedContexts
	 */
	public function testDocumentLoaderServesEachShippedContext(string $url): void {
		$this->assertInstanceOf(\stdClass::class, SignatureService::documentLoader($url));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function allowedContexts(): array {
		return array_map(
			fn (string $url): array => [$url],
			array_keys(SignatureService::LOCAL_CONTEXTS),
		);
	}

	/**
	 * A document's @context is remote input. Resolving an arbitrary URL would open it
	 * with a PHP stream wrapper (SSRF, `file://`, the cloud metadata endpoint) and let
	 * a substituted context change the bytes a signature is computed over. Only the
	 * three shipped contexts may resolve; every other URL must be refused, never
	 * fetched.
	 *
	 * NOTE: the fix constructs `new JsonLdException($msg)` with a single argument, but
	 * that constructor requires a second `$type` argument, so the refusal currently
	 * surfaces as an uncatchable \ArgumentCountError rather than the intended, catchable
	 * \JsonLdException. This assertion pins the security property (a non-allowlisted URL
	 * /**
	 * A document's @context is remote input; resolving one that is not shipped would
	 * mean opening an attacker-chosen URL. An unknown context is refused with a
	 * catchable JsonLdException (not a fatal), so verify() treats it as unverifiable.
	 *
	 * @dataProvider rejectedContexts
	 */
	public function testDocumentLoaderRefusesAnyUrlOutsideTheAllowlist(string $url): void {
		$this->expectException(\JsonLdException::class);
		SignatureService::documentLoader($url);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function rejectedContexts(): array {
		return [
			'remote https context' => ['https://evil.example/context'],
			'local file scheme' => ['file:///etc/passwd'],
			'cloud metadata endpoint' => ['http://169.254.169.254/'],
		];
	}

	/**
	 * JSON-LD normalization loads the @context documents through
	 * SignatureService::documentLoader, which reads them from the app data
	 * folder. Serve the copies shipped in context/ so nothing hits the network.
	 */
	private function registerContextCache(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willReturnCallback(function (string $name) {
			$path = __DIR__ . '/../../context/' . $name;
			if (!is_file($path)) {
				throw new NotFoundException($name);
			}
			$file = $this->createMock(ISimpleFile::class);
			$file->method('getMTime')->willReturn(time());
			$file->method('getContent')->willReturn(file_get_contents($path));

			return $file;
		});
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->with('context')->willReturn($folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with('social')->willReturn($appData);
		\OC::$server->register(IAppDataFactory::class, $factory);
	}

	private function note(): Note {
		$note = new Note();
		$note->setId('https://cloud.example.com/apps/social/@alice/1');
		$note->setActorId(self::LOCAL_ACTOR);
		$note->setAttributedTo(self::LOCAL_ACTOR);
		$note->setContent('<p>Hello fediverse</p>');
		$note->setPublished('2026-09-07T10:00:00Z');
		$note->setTo(ACore::CONTEXT_PUBLIC);

		return $note;
	}

	public function testSignObjectAttachesAnRsaSignature2017(): void {
		$this->registerContextCache();
		$actor = $this->person(self::LOCAL_ACTOR, self::$publicKey, self::$privateKey);
		$note = $this->note();

		$this->service->signObject($actor, $note);

		$this->assertTrue($note->hasSignature());
		$signature = $note->getSignature();
		$this->assertSame('RsaSignature2017', $signature->getType());
		$this->assertSame(self::LOCAL_ACTOR . '#main-key', $signature->getCreator());
		$this->assertNotFalse(DateTime::createFromFormat(SignatureService::DATE_OBJECT, $signature->getCreated()));
		$this->assertNotSame('', $signature->getSignatureValue());
		$this->assertNotFalse(base64_decode($signature->getSignatureValue(), true));

		$json = json_decode(json_encode($note), true);
		$this->assertContains(ACore::CONTEXT_SECURITY, $json['@context']);
		$this->assertSame($signature->getSignatureValue(), $json['signature']['signatureValue']);
	}

	public function testCheckObjectVerifiesASignedObjectAndStampsItsOrigin(): void {
		$this->registerContextCache();
		$signed = $this->note();
		$this->service->signObject($this->person(self::LOCAL_ACTOR, self::$publicKey, self::$privateKey), $signed);
		$received = new Note();
		$received->setSource(json_encode($signed, JSON_UNESCAPED_SLASHES));
		$received->setActorId(self::LOCAL_ACTOR);
		$this->cacheActorService->expects($this->once())
			->method('getFromId')
			->with(self::LOCAL_ACTOR, false)
			->willReturn($this->person(self::LOCAL_ACTOR, self::$publicKey));

		$this->assertTrue($this->service->checkObject($received));
		$this->assertSame(self::CLOUD_HOST, $received->getOrigin());
		$this->assertSame(SignatureService::ORIGIN_SIGNATURE, $received->getOriginSource());
		$this->assertSame((new DateTime($signed->getSignature()->getCreated()))->getTimestamp(), $received->getOriginCreationTime());
	}

	public function testCheckObjectRejectsATamperedObjectAfterRefreshingTheKey(): void {
		$this->registerContextCache();
		$signed = $this->note();
		$this->service->signObject($this->person(self::LOCAL_ACTOR, self::$publicKey, self::$privateKey), $signed);
		$json = json_decode(json_encode($signed, JSON_UNESCAPED_SLASHES), true);
		$json['content'] = '<p>Tampered</p>';
		$received = new Note();
		$received->setSource(json_encode($json, JSON_UNESCAPED_SLASHES));
		$received->setActorId(self::LOCAL_ACTOR);
		$this->cacheActorService->expects($this->exactly(2))
			->method('getFromId')
			->withConsecutive([self::LOCAL_ACTOR, false], [self::LOCAL_ACTOR, true])
			->willReturn($this->person(self::LOCAL_ACTOR, self::$publicKey));

		$this->assertFalse($this->service->checkObject($received));
		$this->assertSame('', $received->getOrigin());
	}

	public function testCheckObjectRejectsASignatureFromAnotherKey(): void {
		$this->registerContextCache();
		$signed = $this->note();
		$this->service->signObject($this->person(self::LOCAL_ACTOR, self::$publicKey, self::$privateKey), $signed);
		$received = new Note();
		$received->setSource(json_encode($signed, JSON_UNESCAPED_SLASHES));
		$received->setActorId(self::LOCAL_ACTOR);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::LOCAL_ACTOR, self::$otherPublicKey));

		$this->assertFalse($this->service->checkObject($received));
	}

	public function testCheckObjectWithoutSignatureIsFalse(): void {
		$received = $this->note();
		$received->setSource(json_encode($received, JSON_UNESCAPED_SLASHES));
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->assertFalse($this->service->checkObject($received));
	}

	/** A note the way it arrives on the wire, signed with the class key pair. */
	private function receivedSignedNote(?string $created = null): Note {
		$signed = $this->note();
		if ($created === null) {
			$this->service->signObject($this->person(self::LOCAL_ACTOR, self::$publicKey, self::$privateKey), $signed);
		} else {
			// signObject() with a chosen creation time: `created` is part of what
			// is signed, so an expired-window test needs a genuine old signature
			$signature = new LinkedDataSignature();
			$signature->setPrivateKey(self::$privateKey);
			$signature->setType('RsaSignature2017');
			$signature->setCreator(self::LOCAL_ACTOR . '#main-key');
			$signature->setCreated($created);
			$signature->setObject(json_decode(json_encode($signed), true));
			$signature->sign();
			$signed->setSignature($signature);
		}

		$received = new Note();
		$received->setSource(json_encode($signed, JSON_UNESCAPED_SLASHES));
		$received->setActorId(self::LOCAL_ACTOR);

		return $received;
	}

	public function testCheckObjectRejectsAReplayedSignature(): void {
		$this->registerContextCache();
		$received = $this->receivedSignedNote();
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::LOCAL_ACTOR, self::$publicKey));

		$this->assertTrue($this->service->checkObject($received), 'first delivery is accepted');
		$this->assertFalse($this->service->checkObject($received), 'the same signature must not verify twice');
	}

	public function testCheckObjectRejectsASignatureOutsideItsWindow(): void {
		$this->registerContextCache();
		$received = $this->receivedSignedNote(
			gmdate(SignatureService::DATE_OBJECT, time() - SignatureService::LD_WINDOW - 3600)
		);
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::LOCAL_ACTOR, self::$publicKey));

		$this->assertFalse($this->service->checkObject($received));
		$this->assertSame('', $received->getOrigin());
	}

	public function testSignRequestWithAnEmptyPrivateKeyFailsLoudly(): void {
		$request = new NCRequest('/users/bob/inbox', Request::TYPE_POST);
		$request->setData(['type' => 'Create']);
		$queue = new RequestQueue('{}', new InstancePath('https://remote.example/users/bob/inbox', InstancePath::TYPE_INBOX), self::LOCAL_ACTOR);
		$this->actorsRequest->method('getFromId')
			->willReturn($this->person(self::LOCAL_ACTOR, self::$publicKey, ''));

		// an undecryptable or missing key used to emit base64('') as the signature
		$this->expectException(SignatureException::class);

		$this->service->signRequest($request, $queue);
	}
}
