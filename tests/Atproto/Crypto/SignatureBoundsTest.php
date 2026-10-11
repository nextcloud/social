<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Crypto;

use InvalidArgumentException;
use OCA\Social\Atproto\Crypto\BigNum;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\Der;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Crypto\PublicKey;
use OCA\Social\Atproto\Protocol\Encoding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The edges of signature checking, where a verifier that only tests the
 * happy path goes wrong: r or s of zero or at the group order, a high S
 * where AT Protocol wants the low one (and taken where JOSE allows
 * either), a signature of the wrong length; did:keys of another key type
 * or not base58; and the DER the OpenSSL side speaks, malformed.
 */
class SignatureBoundsTest extends TestCase {
	private const MESSAGE = 'the bytes of an unsigned commit';

	public static function curves(): array {
		return ['K-256' => [Curve::K256], 'P-256' => [Curve::P256]];
	}

	#[DataProvider('curves')]
	public function testRAndSOutsideTheGroupAreRefused(Curve $curve): void {
		$key = PrivateKey::generate($curve);
		$signature = $key->sign(self::MESSAGE);
		$public = $key->publicKey();
		$r = substr($signature, 0, 32);
		$s = substr($signature, 32);
		$order = $curve->order()->toBytes(32);
		$zero = str_repeat("\0", 32);

		$this->assertTrue($public->verify(self::MESSAGE, $signature));
		foreach ([
			'r zero' => $zero . $s,
			's zero' => $r . $zero,
			'r the order' => $order . $s,
			's the order' => $r . $order,
			'all ones' => str_repeat("\xff", 64),
			'63 bytes' => substr($signature, 0, 63),
			'65 bytes' => $signature . "\0",
			'DER instead of r || s' => Der::sequence(Der::unsignedInteger(BigNum::fromBytes($r)) . Der::unsignedInteger(BigNum::fromBytes($s))),
		] as $why => $forged) {
			$this->assertFalse($public->verify(self::MESSAGE, $forged), $why);
			$this->assertFalse($public->verify(self::MESSAGE, $forged, false), $why . ', either S taken');
		}
	}

	/**
	 * The same signature with S replaced by n - S verifies mathematically;
	 * AT Protocol refuses it so that a signature has one form, a JOSE
	 * signature may come in either.
	 */
	#[DataProvider('curves')]
	public function testTheHighSFormIsRefusedUnlessEitherIsTaken(Curve $curve): void {
		$key = PrivateKey::generate($curve);
		$signature = $key->sign(self::MESSAGE);
		$s = BigNum::fromBytes(substr($signature, 32));
		$this->assertLessThanOrEqual(0, $s->compare($curve->halfOrder()), 'this app signs low-S');
		$high = substr($signature, 0, 32) . $curve->order()->subtract($s)->toBytes(32);

		$this->assertFalse($key->publicKey()->verify(self::MESSAGE, $high));
		$this->assertTrue($key->publicKey()->verify(self::MESSAGE, $high, false));
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function foreignDidKeys(): array {
		return [
			'ed25519' => ['did:key:z6MkhaXgBZDvotDkL5257faiztiGiC2QtKLGpbnnEGta2doK'],
			'not base58btc' => ['did:key:mAOqSXF5wJRbWtvlMMKdpmQ'],
			'not base58 at all' => ['did:key:z0OIl'],
			'a did:web' => ['did:web:social.example.com'],
			'a K-256 prefix with a short point' => ['did:key:z' . Encoding::base58Encode("\xe7\x01\x02" . str_repeat("\x11", 20))],
			'a K-256 prefix with an uncompressed point' => ['did:key:z' . Encoding::base58Encode("\xe7\x01\x04" . str_repeat("\x11", 64))],
			'nothing after the prefix' => ['did:key:z'],
		];
	}

	#[DataProvider('foreignDidKeys')]
	public function testADidKeyThatIsNotAK256OrP256KeyIsRefused(string $didKey): void {
		$this->expectException(InvalidArgumentException::class);
		PublicKey::fromDidKey($didKey);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function malformedDer(): array {
		$one = "\x02\x01\x01";

		return [
			'empty' => [''],
			'not a sequence' => ["\x31\x06" . $one . $one],
			'a length past the end' => ["\x30\x07" . $one . $one],
			'trailing bytes inside' => ["\x30\x08" . $one . $one . "\x05\x00"],
			'one integer' => ["\x30\x03" . $one],
			'a negative integer' => ["\x30\x06\x02\x01\x81" . $one],
			'an empty integer' => ["\x30\x05\x02\x00" . $one],
			'an indefinite length' => ["\x30\x80" . $one . $one],
			'a four-byte length' => ["\x30\x84\x00\x00\x00\x06" . $one . $one],
		];
	}

	#[DataProvider('malformedDer')]
	public function testMalformedDerIsRefused(string $der): void {
		$this->expectException(InvalidArgumentException::class);
		Der::parseSignature($der);
	}

	public function testDerRoundTripsAcrossTheLengthForms(): void {
		foreach (['01', '7f', '80', 'ff' . str_repeat('00', 31), str_repeat('ab', 32)] as $hex) {
			$value = BigNum::fromHex($hex);
			[$r, $s] = Der::parseSignature(Der::sequence(Der::unsignedInteger($value) . Der::unsignedInteger(BigNum::fromInt(1))));

			$this->assertSame(0, $r->compare($value), $hex);
			$this->assertSame(0, $s->compare(BigNum::fromInt(1)));
		}
		$long = Der::sequence(str_repeat("\x05\x00", 100));
		$this->assertSame("\x30\x81\xc8", substr($long, 0, 3), 'the two-byte length form');
	}
}
