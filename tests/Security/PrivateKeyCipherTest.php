<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Security;

use Exception;
use OCA\Social\Security\PrivateKeyCipher;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class PrivateKeyCipherTest extends TestCase {
	private const PEM = "-----BEGIN PRIVATE KEY-----\nMIIEvT...\n-----END PRIVATE KEY-----\n";

	/** @var ICrypto&MockObject */
	private $crypto;
	private PrivateKeyCipher $cipher;

	protected function setUp(): void {
		$this->crypto = $this->createMock(ICrypto::class);
		$this->crypto->method('encrypt')->willReturnCallback(fn (string $m): string => 'sealed(' . $m . ')');
		$this->crypto->method('decrypt')->willReturnCallback(function (string $c): string {
			if (!preg_match('/^sealed\((.*)\)$/s', $c, $m)) {
				throw new Exception('HMAC does not match');
			}

			return $m[1];
		});

		$this->cipher = new PrivateKeyCipher($this->crypto, new NullLogger());
	}

	public function testAKeyRoundTrips(): void {
		$stored = $this->cipher->seal(self::PEM);

		$this->assertSame('sealed(' . self::PEM . ')', $stored, 'the stored value goes through ICrypto');
		$this->assertSame(self::PEM, $this->cipher->open($stored));
	}

	public function testAnEmptyKeyStaysEmpty(): void {
		$this->assertSame('', $this->cipher->seal(''));
		$this->assertSame('', $this->cipher->open(''));
	}

	public function testALegacyPlaintextKeyIsStillReadable(): void {
		$this->crypto->expects($this->never())->method('decrypt');

		$this->assertSame(self::PEM, $this->cipher->open(self::PEM));
	}

	public function testAnUndecryptableValueYieldsAnEmptyKey(): void {
		$this->assertSame('', $this->cipher->open('not-something-we-sealed'));
	}

	/**
	 * After the instance secret changed, a value sealed with the old one
	 * is opened with it and sealed again with the new one; one that opens
	 * already is sealed afresh, one that neither opens yields nothing.
	 */
	public function testAValueSealedWithTheOldSecretIsSealedAgainWithTheNewOne(): void {
		$crypto = $this->createMock(ICrypto::class);
		$secret = 'new secret';
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain, string $password = ''): string => base64_encode(($password === '' ? $secret : $password) . '|' . $plain));
		$crypto->method('decrypt')->willReturnCallback(static function (string $sealed, string $password = '') use ($secret): string {
			[$with, $plain] = explode('|', (string)base64_decode($sealed), 2) + ['', ''];
			if ($with !== ($password === '' ? $secret : $password)) {
				throw new Exception('HMAC does not match');
			}

			return $plain;
		});
		$cipher = new PrivateKeyCipher($crypto, new NullLogger());
		$old = $crypto->encrypt(self::PEM, 'old secret');
		$this->assertFalse($cipher->opens($old));
		$this->assertSame('', $cipher->open($old));

		$resealed = $cipher->reseal($old, 'old secret');

		$this->assertTrue($cipher->opens($resealed));
		$this->assertSame(self::PEM, $cipher->open($resealed));
		$this->assertSame($resealed, $cipher->reseal($resealed, 'old secret'), 'one that opens already is sealed under the current secret as it was');
		$this->assertSame($resealed, $cipher->reseal(self::PEM, ''), 'legacy plaintext is sealed');
		$this->assertSame('', $cipher->reseal($old, 'another secret'), 'neither secret opens it');
		$this->assertSame('', $cipher->reseal($old, ''));
		$this->assertSame('', $cipher->reseal('', 'old secret'));
		$this->assertTrue($cipher->opens(''));
		$this->assertTrue($cipher->opens(self::PEM));
	}

	public function testIsPlainRecognisesAPem(): void {
		$this->assertTrue($this->cipher->isPlain(self::PEM));
		$this->assertFalse($this->cipher->isPlain($this->cipher->seal(self::PEM)));
		$this->assertFalse($this->cipher->isPlain(''));
	}
}
