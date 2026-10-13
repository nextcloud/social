<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\AppView;

use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Db\AtprotoOAuthRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Service-auth tokens that arrive here (an account moving in signs one for
 * `createAccount`): the forgeries a JWT verifier is known to fall for are
 * refused. The algorithm is the key's own, never `none` or an HMAC keyed
 * with something public; the audience, the method and the expiry are
 * required claims, not optional ones.
 */
#[AllowMockObjectsWithoutExpectations]
class ServiceAuthForgeryTest extends TestCase {
	private const NOW = 1760000000;
	private const AUD = 'did:web:social.example.com';
	private const LXM = 'com.atproto.server.createAccount';

	private static ?PrivateKey $key = null;
	private ServiceAuth $auth;

	protected function setUp(): void {
		self::$key ??= PrivateKey::generate(Curve::K256);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->auth = new ServiceAuth($time, $this->replay());
	}

	public function testTheGenuineTokenIsTaken(): void {
		$this->assertTrue($this->auth->verify(self::token(['alg' => 'ES256K', 'typ' => 'JWT'], self::claims()), self::$key->publicKey(), self::AUD, self::LXM));
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function forgeries(): array {
		self::$key ??= PrivateKey::generate(Curve::K256);
		$claims = self::claims();
		$part = static fn (array $value): string => Encoding::base64UrlEncode((string)json_encode($value, JSON_UNESCAPED_SLASHES));
		$input = static fn (array $header, array $claims): string => $part($header) . '.' . $part($claims);
		$hs256 = $input(['alg' => 'HS256', 'typ' => 'JWT'], $claims);

		return [
			'alg none, no signature' => [$input(['alg' => 'none', 'typ' => 'JWT'], $claims) . '.'],
			'alg none, with the signature of a genuine token' => [$input(['alg' => 'none', 'typ' => 'JWT'], $claims) . '.' . explode('.', self::token(['alg' => 'ES256K', 'typ' => 'JWT'], $claims))[2]],
			'HS256 keyed with the public key' => [$hs256 . '.' . Encoding::base64UrlEncode(hash_hmac('sha256', $hs256, self::$key->publicKey()->compressed(), true))],
			'HS256 keyed with the did:key' => [$hs256 . '.' . Encoding::base64UrlEncode(hash_hmac('sha256', $hs256, self::$key->publicKey()->didKey(), true))],
			'ES256 claimed for a secp256k1 key' => [self::token(['alg' => 'ES256', 'typ' => 'JWT'], $claims)],
			'no algorithm' => [self::token(['typ' => 'JWT'], $claims)],
			'no audience' => [self::token(['alg' => 'ES256K'], array_diff_key($claims, ['aud' => true]))],
			'no method' => [self::token(['alg' => 'ES256K'], array_diff_key($claims, ['lxm' => true]))],
			'any method' => [self::token(['alg' => 'ES256K'], ['lxm' => '*'] + $claims)],
			'no expiry' => [self::token(['alg' => 'ES256K'], array_diff_key($claims, ['exp' => true]))],
			'expired a second ago' => [self::token(['alg' => 'ES256K'], ['exp' => self::NOW - 1] + $claims)],
			'expiring now' => [self::token(['alg' => 'ES256K'], ['exp' => self::NOW] + $claims)],
			'an audience with a service fragment' => [self::token(['alg' => 'ES256K'], ['aud' => self::AUD . '#atproto_pds'] + $claims)],
			'two parts' => [implode('.', array_slice(explode('.', self::token(['alg' => 'ES256K'], $claims)), 0, 2))],
			'four parts' => [self::token(['alg' => 'ES256K'], $claims) . '.x'],
			'a payload swapped under a genuine signature' => [(static function () use ($claims, $part): string {
				[$header, , $signature] = explode('.', self::token(['alg' => 'ES256K'], ['lxm' => 'com.atproto.repo.importRepo'] + $claims));

				return $header . '.' . $part($claims) . '.' . $signature;
			})()],
			'not base64' => ['***.***.***'],
		];
	}

	#[DataProvider('forgeries')]
	public function testAForgedTokenIsRefused(string $token): void {
		$this->assertFalse($this->auth->verify($token, self::$key->publicKey(), self::AUD, self::LXM));
	}

	/** A replay record that remembers what it was given, as the table does. */
	private function replay(): AtprotoOAuthRequest {
		$seen = [];
		$replay = $this->createStub(AtprotoOAuthRequest::class);
		$replay->method('firstUse')->willReturnCallback(static function (string $hash) use (&$seen): bool {
			if (isset($seen[$hash])) {
				return false;
			}
			$seen[$hash] = true;

			return true;
		});

		return $replay;
	}

	private static function claims(): array {
		return ['iss' => 'did:plc:ewvi7nxzyoun6zhxrhs64oiz', 'aud' => self::AUD, 'lxm' => self::LXM, 'iat' => self::NOW, 'exp' => self::NOW + 60, 'jti' => 'a'];
	}

	private static function token(array $header, array $claims): string {
		$input = Encoding::base64UrlEncode((string)json_encode($header)) . '.' . Encoding::base64UrlEncode((string)json_encode($claims, JSON_UNESCAPED_SLASHES));

		return $input . '.' . Encoding::base64UrlEncode((self::$key ??= PrivateKey::generate(Curve::K256))->sign($input));
	}
}
