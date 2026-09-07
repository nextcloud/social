<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use DateTime;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SignatureIsGoneException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
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
	private SignatureService $service;

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
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getCloudHost')->willReturn(self::CLOUD_HOST);

		$this->service = new SignatureService(
			$this->actorsRequest,
			$this->cacheActorService,
			$this->createMock(CurlService::class),
			$configService,
			new NullLogger(),
		);
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
			->with(self::REMOTE_KEY_ID, false)
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
		$this->expectExceptionMessage('issue with digest');
		$this->service->checkRequest($this->incomingRequest($headers), $tampered);
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

	public function testCheckRequestRefreshesTheKeyOnceBeforeGivingUp(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->expects($this->exactly(2))
			->method('getFromId')
			->withConsecutive([self::REMOTE_KEY_ID, false], [self::REMOTE_KEY_ID, true])
			->willReturn($this->person(self::REMOTE_ACTOR, self::$otherPublicKey));

		$this->assertSame('', $this->service->checkRequest($this->incomingRequest($headers), $body));
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

	public function testCheckRequestWithAnUnknownActorYieldsNoOrigin(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->method('getFromId')->willThrowException(new RequestContentException('not found', 404));

		$this->assertSame('', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function testCheckRequestSignalsAGoneActor(): void {
		$body = '{"type":"Delete"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->method('getFromId')->willThrowException(new RequestContentException('gone', 410));

		$this->expectException(SignatureIsGoneException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRequiresRequestTargetAndDateToBeSigned(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, [], 'host digest');
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->assertSame('', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function testCheckRequestRejectsAKeyIdWithoutHost(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, [], '(request-target) host date digest', 'rsa-sha256', 'main-key');

		$this->expectException(InvalidOriginException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
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
}
