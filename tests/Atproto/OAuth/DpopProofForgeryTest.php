<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\OAuth;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\OAuth\DpopNonce;
use OCA\Social\Atproto\OAuth\DpopVerifier;
use OCA\Social\Atproto\OAuth\OAuthException;
use OCA\Social\Atproto\Protocol\Encoding;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DPoP proofs (RFC 9449) a forger makes rather than a client: a proof
 * whose algorithm is not its key's, whose key is not a public point on the
 * curve, whose id is missing, or whose target differs from this request in
 * scheme, port or path is refused, and a replay is refused on whichever
 * request it is replayed to.
 */
#[AllowMockObjectsWithoutExpectations]
class DpopProofForgeryTest extends TestCase {
	private const URL = 'https://social.example.com/xrpc/com.atproto.repo.createRecord';
	private const NOW = 1760000000;

	private PrivateKey $key;
	private DpopNonce $nonce;
	private DpopVerifier $verifier;

	protected function setUp(): void {
		$this->key = PrivateKey::generate(Curve::K256);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(static fn (string $message): string => hash_hmac('sha256', $message, 'instance secret', true));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->nonce = new DpopNonce($crypto, $time);
		$request = (new \ReflectionClass(InMemoryOAuthRequest::class))->newInstanceWithoutConstructor();
		$this->verifier = new DpopVerifier($this->nonce, $request, $time);
	}

	public function testAGenuineSecp256k1ProofIsTaken(): void {
		$this->assertNotSame('', $this->verifier->verify($this->proof(), 'POST', self::URL));
	}

	/**
	 * @return array<string, array{0: callable(self): string}>
	 */
	public static function forgeries(): array {
		return [
			'alg none' => [static fn (self $t): string => $t->unsigned(['typ' => 'dpop+jwt', 'alg' => 'none', 'jwk' => OAuthKit::jwk($t->key)]) . '.'],
			'HS256 keyed with the public point' => [static function (self $t): string {
				$input = $t->unsigned(['typ' => 'dpop+jwt', 'alg' => 'HS256', 'jwk' => OAuthKit::jwk($t->key)]);

				return $input . '.' . Encoding::base64UrlEncode(hash_hmac('sha256', $input, $t->key->publicKey()->x, true));
			}],
			'ES256 claimed for a secp256k1 key' => [static fn (self $t): string => OAuthKit::sign($t->key, ['typ' => 'dpop+jwt', 'alg' => 'ES256', 'jwk' => OAuthKit::jwk($t->key)], $t->claims())],
			'a private key in the header' => [static fn (self $t): string => OAuthKit::sign($t->key, ['typ' => 'dpop+jwt', 'alg' => 'ES256K', 'jwk' => OAuthKit::jwk($t->key, ['d' => Encoding::base64UrlEncode($t->key->secret())])], $t->claims())],
			'a key that is not on the curve' => [static fn (self $t): string => OAuthKit::sign($t->key, ['typ' => 'dpop+jwt', 'alg' => 'ES256K', 'jwk' => ['y' => Encoding::base64UrlEncode(str_repeat("\1", 32))] + OAuthKit::jwk($t->key)], $t->claims())],
			'an RSA key' => [static fn (self $t): string => OAuthKit::sign($t->key, ['typ' => 'dpop+jwt', 'alg' => 'ES256K', 'jwk' => ['kty' => 'RSA', 'n' => 'AQAB', 'e' => 'AQAB']], $t->claims())],
			'no key at all' => [static fn (self $t): string => OAuthKit::sign($t->key, ['typ' => 'dpop+jwt', 'alg' => 'ES256K'], $t->claims())],
			'no id' => [static fn (self $t): string => OAuthKit::sign($t->key, ['typ' => 'dpop+jwt', 'alg' => 'ES256K', 'jwk' => OAuthKit::jwk($t->key)], array_diff_key($t->claims(), ['jti' => true]))],
			'an id past 256 bytes' => [static fn (self $t): string => OAuthKit::sign($t->key, ['typ' => 'dpop+jwt', 'alg' => 'ES256K', 'jwk' => OAuthKit::jwk($t->key)], ['jti' => str_repeat('a', 257)] + $t->claims())],
			'no issue time' => [static fn (self $t): string => OAuthKit::sign($t->key, ['typ' => 'dpop+jwt', 'alg' => 'ES256K', 'jwk' => OAuthKit::jwk($t->key)], array_diff_key($t->claims(), ['iat' => true]))],
			'an issue time as text' => [static fn (self $t): string => OAuthKit::sign($t->key, ['typ' => 'dpop+jwt', 'alg' => 'ES256K', 'jwk' => OAuthKit::jwk($t->key)], ['iat' => (string)self::NOW] + $t->claims())],
			'plain http' => [static fn (self $t): string => OAuthKit::proof($t->key, 'POST', str_replace('https:', 'http:', self::URL), self::NOW, $t->nonce->current())],
			'another port' => [static fn (self $t): string => OAuthKit::proof($t->key, 'POST', str_replace('.com/', '.com:8443/', self::URL), self::NOW, $t->nonce->current())],
			'another method on the same host' => [static fn (self $t): string => OAuthKit::proof($t->key, 'POST', str_replace('createRecord', 'deleteRecord', self::URL), self::NOW, $t->nonce->current())],
			'a path prefix' => [static fn (self $t): string => OAuthKit::proof($t->key, 'POST', 'https://social.example.com/xrpc/', self::NOW, $t->nonce->current())],
			'a JWS too long to be a proof' => [static fn (self $t): string => OAuthKit::sign($t->key, ['typ' => 'dpop+jwt', 'alg' => 'ES256K', 'jwk' => OAuthKit::jwk($t->key)], ['pad' => str_repeat('a', 9000)] + $t->claims())],
		];
	}

	/**
	 * @param callable(self): string $forge
	 */
	#[DataProvider('forgeries')]
	public function testAForgedProofIsRefused(callable $forge): void {
		try {
			$this->verifier->verify($forge($this), 'POST', self::URL);
			$this->fail('taken');
		} catch (OAuthException $e) {
			$this->assertSame('invalid_dpop_proof', $e->error);
			$this->assertSame(400, $e->status);
		}
	}

	public function testTheMethodIsComparedWithoutCase(): void {
		$proof = OAuthKit::proof($this->key, 'post', self::URL, self::NOW, $this->nonce->current());

		$this->assertNotSame('', $this->verifier->verify($proof, 'POST', self::URL));
	}

	public function testAReplayIsRefusedOnAnyRequest(): void {
		$proof = $this->proof();
		$this->verifier->verify($proof, 'POST', self::URL);

		$this->expectException(OAuthException::class);
		$this->expectExceptionMessage('used before');
		$this->verifier->verify($proof, 'POST', self::URL . '?other=query');
	}

	/**
	 * The same id under another key is another proof: replay protection is
	 * per key, so one client cannot lock out another by guessing ids.
	 */
	public function testTheSameIdUnderAnotherKeyIsNotAReplay(): void {
		$this->verifier->verify(OAuthKit::proof($this->key, 'POST', self::URL, self::NOW, $this->nonce->current(), '', 'shared'), 'POST', self::URL);

		$this->assertNotSame('', $this->verifier->verify(OAuthKit::proof(PrivateKey::generate(Curve::P256), 'POST', self::URL, self::NOW, $this->nonce->current(), '', 'shared'), 'POST', self::URL));
	}

	private function proof(): string {
		return OAuthKit::proof($this->key, 'POST', self::URL, self::NOW, $this->nonce->current());
	}

	private function claims(): array {
		return ['jti' => bin2hex(random_bytes(8)), 'htm' => 'POST', 'htu' => self::URL, 'iat' => self::NOW, 'nonce' => $this->nonce->current()];
	}

	private function unsigned(array $header): string {
		return Encoding::base64UrlEncode((string)json_encode($header, JSON_UNESCAPED_SLASHES)) . '.' . Encoding::base64UrlEncode((string)json_encode($this->claims(), JSON_UNESCAPED_SLASHES));
	}
}
