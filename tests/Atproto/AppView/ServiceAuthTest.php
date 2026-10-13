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
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ServiceAuthTest extends TestCase {
	public function testATokenIsSignedByTheUserForOneServiceAndMethod(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$key = PrivateKey::generate(Curve::K256);
		$token = (new ServiceAuth($time, $this->createStub(AtprotoOAuthRequest::class)))->token($key, 'did:plc:ewvi7nxzyoun6zhxrhs64oiz', 'did:web:api.bsky.app', 'app.bsky.notification.listNotifications');

		[$header, $payload, $signature] = explode('.', $token);
		$this->assertSame(['typ' => 'JWT', 'alg' => 'ES256K'], json_decode(Encoding::base64UrlDecode($header), true));
		$claims = json_decode(Encoding::base64UrlDecode($payload), true);
		$this->assertSame('did:plc:ewvi7nxzyoun6zhxrhs64oiz', $claims['iss']);
		$this->assertSame('did:web:api.bsky.app', $claims['aud']);
		$this->assertSame('app.bsky.notification.listNotifications', $claims['lxm']);
		$this->assertSame(1760000000, $claims['iat']);
		$this->assertSame(1760000000 + ServiceAuth::LIFETIME, $claims['exp']);
		$this->assertSame(32, strlen((string)$claims['jti']));
		$this->assertTrue($key->publicKey()->verify($header . '.' . $payload, Encoding::base64UrlDecode($signature)), 'the signature covers header and payload');
		$this->assertFalse(PrivateKey::generate(Curve::K256)->publicKey()->verify($header . '.' . $payload, Encoding::base64UrlDecode($signature)));
	}

	public function testATokenThatArrivesIsCheckedForKeyServiceMethodAndTime(): void {
		$now = 1760000000;
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(function () use (&$now) {
			return $now;
		});
		$auth = new ServiceAuth($time, $this->replay());
		$key = PrivateKey::generate(Curve::K256);
		$token = $auth->token($key, 'did:plc:alice', 'did:web:social.example.com', 'com.atproto.server.createAccount');

		$this->assertSame('did:plc:alice', $auth->issuer($token));
		$this->assertTrue($auth->verify($token, $key->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'));
		$this->assertFalse($auth->verify($token, PrivateKey::generate(Curve::K256)->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'), 'another key');
		$this->assertFalse($auth->verify($token, PrivateKey::generate(Curve::P256)->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'), 'another curve');
		$this->assertFalse($auth->verify($token, $key->publicKey(), 'did:web:other.example.com', 'com.atproto.server.createAccount'), 'another service');
		$this->assertFalse($auth->verify($token, $key->publicKey(), 'did:web:social.example.com', 'com.atproto.repo.importRepo'), 'another method');
		[$header, , $signature] = explode('.', $token);
		$forged = $header . '.' . Encoding::base64UrlEncode((string)json_encode(['iss' => 'did:plc:alice', 'aud' => 'did:web:social.example.com', 'lxm' => 'com.atproto.server.createAccount', 'exp' => $now + 3600])) . '.' . $signature;
		$this->assertFalse($auth->verify($forged, $key->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'), 'claims changed after signing');
		$this->assertSame('', $auth->issuer('not a token'));

		$now += ServiceAuth::LIFETIME;
		$this->assertFalse($auth->verify($token, $key->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'), 'expired');
	}

	/** A token is good for one request: its id is recorded, and a second presentation is refused. */
	public function testATokenIsTakenOnce(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$auth = new ServiceAuth($time, $this->replay());
		$key = PrivateKey::generate(Curve::K256);
		$token = $auth->token($key, 'did:plc:alice', 'did:web:social.example.com', 'com.atproto.server.createAccount');
		$another = $auth->token($key, 'did:plc:alice', 'did:web:social.example.com', 'com.atproto.server.createAccount');

		$this->assertTrue($auth->verify($token, $key->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'));
		$this->assertFalse($auth->verify($token, $key->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'), 'presented before');
		$this->assertTrue($auth->verify($another, $key->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'), 'a token of its own');
	}

	public function testATokenWithoutAnIdIsRefused(): void {
		$now = 1760000000;
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);
		$auth = new ServiceAuth($time, $this->replay());
		$key = PrivateKey::generate(Curve::K256);
		$claims = ['iss' => 'did:plc:alice', 'aud' => 'did:web:social.example.com', 'lxm' => 'com.atproto.server.createAccount', 'iat' => $now, 'exp' => $now + 60];

		$this->assertTrue($auth->verify(self::signed($key, $claims + ['jti' => 'one']), $key->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'));
		$this->assertFalse($auth->verify(self::signed($key, $claims), $key->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'), 'no jti');
		$this->assertFalse($auth->verify(self::signed($key, $claims + ['jti' => '']), $key->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'), 'empty jti');
	}

	/** A token dated further ahead than a clock may be off by is refused; within the skew it is taken. */
	public function testATokenDatedInTheFutureIsRefused(): void {
		$now = 1760000000;
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);
		$auth = new ServiceAuth($time, $this->replay());
		$key = PrivateKey::generate(Curve::K256);
		$claims = ['iss' => 'did:plc:alice', 'aud' => 'did:web:social.example.com', 'lxm' => 'com.atproto.server.createAccount', 'exp' => $now + 600];

		$this->assertFalse($auth->verify(self::signed($key, $claims + ['iat' => $now + ServiceAuth::SKEW + 1, 'jti' => 'ahead']), $key->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'));
		$this->assertTrue($auth->verify(self::signed($key, $claims + ['iat' => $now + ServiceAuth::SKEW, 'jti' => 'skewed']), $key->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'));
	}

	/** The replay record outlives the token: its expiry plus the skew. */
	public function testTheIdIsRecordedForTheTokensLifetime(): void {
		$now = 1760000000;
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);
		$recorded = [];
		$replay = $this->createStub(AtprotoOAuthRequest::class);
		$replay->method('firstUse')->willReturnCallback(static function (string $hash, int $expires) use (&$recorded): bool {
			$recorded = [$hash, $expires];

			return true;
		});
		$auth = new ServiceAuth($time, $replay);
		$key = PrivateKey::generate(Curve::K256);

		$auth->verify($auth->token($key, 'did:plc:alice', 'did:web:social.example.com', 'com.atproto.server.createAccount', 300), $key->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount');

		$this->assertSame($now + 300 + ServiceAuth::SKEW, $recorded[1]);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $recorded[0]);
	}

	private static function signed(PrivateKey $key, array $claims): string {
		$input = Encoding::base64UrlEncode((string)json_encode(['typ' => 'JWT', 'alg' => 'ES256K'])) . '.' . Encoding::base64UrlEncode((string)json_encode($claims, JSON_UNESCAPED_SLASHES));

		return $input . '.' . Encoding::base64UrlEncode($key->sign($input));
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
}
