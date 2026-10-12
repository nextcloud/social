<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Federation;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\HttpSignatureService;
use OCA\Social\Service\InstanceActorService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * One signed inbox delivery per implementation, through the real
 * `SignatureService::checkRequest()`.
 *
 * Signature handling has been relaxed and tightened more than once, and each
 * change was checked against the implementations somebody had in mind at the
 * time. These are the nine shapes this instance has to keep taking:
 * Mastodon's draft-cavage and its RFC 9421 retry, GoToSocial, Misskey,
 * Akkoma/Pleroma, Lemmy, Friendica, PeerTube and Pixelfed. Each vector in
 * `signatures/` is signed the way that project's source signs — its header
 * set and order, algorithm label, keyId shape, digest and date — and says
 * which source it follows; none was captured from a live server
 * (`signatures/README.md`).
 *
 * Only the key lookup is stood in for, with the public key the vector was
 * signed with: the parsing, the covered-header rules, the digest, the date
 * window and the cryptography are the production code.
 */
#[AllowMockObjectsWithoutExpectations]
class SignatureVectorsTest extends TestCase {
	/** @return array<string, array{0: string}> */
	public static function vectors(): array {
		$vectors = [];
		foreach (glob(__DIR__ . '/signatures/*.json') ?: [] as $file) {
			$vectors[basename($file, '.json')] = [$file];
		}

		return $vectors;
	}

	/** Every implementation the vectors are meant to cover has one shaped after its source. */
	public function testThereIsAVectorForEveryImplementation(): void {
		$this->assertSame(
			['akkoma', 'friendica', 'gotosocial', 'lemmy', 'mastodon-cavage', 'mastodon-rfc9421', 'misskey', 'peertube', 'pixelfed'],
			array_values(array_filter(array_keys(self::vectors()), static fn (string $name): bool => !str_starts_with($name, 'captured-')))
		);
	}

	#[DataProvider('vectors')]
	public function testTheDeliveryIsAcceptedAndSpeaksForItsActor(string $file): void {
		$vector = self::read($file);

		$time = 0;
		$signer = '';
		$origin = $this->service($vector)->checkRequest($this->request($vector), $vector['body'], $time, $signer);

		$this->assertSame(parse_url($vector['actor'], PHP_URL_HOST), $origin, $vector['implementation'] . ': the origin is not the actor\'s host');
		$this->assertSame($vector['actor'], $signer, $vector['implementation'] . ': the signature does not speak for its actor');
		$this->assertSame($vector['signedAt'], $time, $vector['implementation'] . ': the request is not dated when it was signed');
	}

	/**
	 * The same delivery with its body changed is refused: the vector is
	 * verified, not waved through.
	 */
	#[DataProvider('vectors')]
	public function testTheDeliveryWithAnotherBodyIsRefused(string $file): void {
		$vector = self::read($file);
		$body = str_replace('Follow', 'Block', $vector['body']);

		$this->expectException(SignatureException::class);
		$this->service($vector)->checkRequest($this->request($vector), $body);
	}

	/** And so is the same delivery signed by somebody else's key. */
	#[DataProvider('vectors')]
	public function testTheDeliveryIsRefusedWithAnotherKey(string $file): void {
		$vector = self::read($file);
		$other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$vector['publicKeyPem'] = openssl_pkey_get_details($other)['key'];

		$this->expectException(SignatureException::class);
		$this->service($vector)->checkRequest($this->request($vector), $vector['body']);
	}

	/** @return array<string, mixed> */
	private static function read(string $file): array {
		return json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * The real service, at the moment the vector was signed, with the actor's
	 * key as the only thing any lookup can answer.
	 *
	 * @param array<string, mixed> $vector
	 */
	private function service(array $vector): SignatureService {
		$actor = new Person();
		$actor->setId($vector['actor']);
		$actor->setPublicKey($vector['publicKeyPem']);

		$cacheActorsRequest = $this->createStub(CacheActorsRequest::class);
		$cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$cacheActorService = $this->createStub(CacheActorService::class);
		$cacheActorService->method('getFromId')->willReturnCallback(
			static fn (string $id): Person => ($id === $vector['actor']) ? $actor : throw new CacheActorDoesNotExistException($id)
		);
		$cacheActorService->method('getFromKeyId')->willReturnCallback(
			static fn (string $keyId): Person => ($keyId === $vector['keyId']) ? $actor : throw new CacheActorDoesNotExistException($keyId)
		);

		$configService = $this->createStub(ConfigService::class);
		$configService->method('getCloudHost')->willReturn($vector['host']);
		$configService->method('getCloudAuthority')->willReturn($vector['host']);
		$configService->method('withRequestTimeout')->willReturnCallback(static fn (int $timeout, callable $action) => $action());

		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);
		$cacheFactory->method('createDistributed')->willReturn($this->createStub(ICache::class));

		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn($vector['signedAt'] + 30);

		return new SignatureService(
			$cacheActorService,
			$cacheActorsRequest,
			$configService,
			new HttpSignatureService(
				$this->createStub(ActorsRequest::class), $this->createStub(InstanceActorService::class), new NullLogger()
			),
			$cacheFactory,
			new NullLogger(),
			new DurableCache($cacheFactory, new InMemoryDurableCacheRequest(), $clock),
			$this->createStub(FediverseService::class),
			$clock,
		);
	}

	/**
	 * The delivery as this instance's request object shows it: header names
	 * case-insensitive, the path as the request URI, over https.
	 *
	 * @param array<string, mixed> $vector
	 */
	private function request(array $vector): IRequest {
		$headers = array_change_key_case($vector['headers'], CASE_LOWER);
		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => (string)($headers[strtolower($name)] ?? ''));
		$request->method('getMethod')->willReturn($vector['method']);
		$request->method('getRequestUri')->willReturn($vector['path']);
		$request->method('getServerProtocol')->willReturn('https');

		return $request;
	}
}
