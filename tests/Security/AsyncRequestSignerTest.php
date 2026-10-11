<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Security;

use OCA\Social\Security\AsyncRequestSigner;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The signature that lets `/async/request/{token}` tell this server's own
 * call from anybody else's. The route is public and the tokens are written
 * to the log, so the signature is all that keeps a reader of the log from
 * making a worker deliver on demand.
 */
class AsyncRequestSignerTest extends TestCase {
	private function signer(string $secret = 'instance-secret'): AsyncRequestSigner {
		$crypto = $this->createStub(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(
			static fn (string $message, string $password = ''): string => hash_hmac('sha512', $message, $secret, true)
		);

		return new AsyncRequestSigner($crypto);
	}

	public function testASignatureVerifiesForTheTokenItWasMadeFor(): void {
		$signer = $this->signer();

		$this->assertTrue($signer->verify('tok-1', $signer->sign('tok-1')));
	}

	public function testASignatureIsOfNoUseForAnotherToken(): void {
		$signer = $this->signer();

		$this->assertFalse($signer->verify('tok-2', $signer->sign('tok-1')));
	}

	/** Only the holder of the instance secret can produce one. */
	public function testASignatureMadeUnderAnotherSecretIsRefused(): void {
		$this->assertFalse($this->signer()->verify('tok-1', $this->signer('other-secret')->sign('tok-1')));
	}

	/**
	 * The token is signed under a prefix of its own, so an HMAC the instance
	 * secret makes for another purpose over the same string is not a
	 * signature here.
	 */
	public function testTheTokenIsSignedUnderItsOwnPrefix(): void {
		$signer = $this->signer();

		$this->assertSame(bin2hex(hash_hmac('sha512', 'social-async-request:tok-1', 'instance-secret', true)), $signer->sign('tok-1'));
		$this->assertFalse($signer->verify('tok-1', bin2hex(hash_hmac('sha512', 'tok-1', 'instance-secret', true))));
	}

	/** Lower-case hex, so it travels in a header unchanged. */
	public function testTheSignatureIsHex(): void {
		$this->assertMatchesRegularExpression('/^[0-9a-f]{128}$/', $this->signer()->sign('tok-1'));
	}

	/** @return array<string, array{string, string}> */
	public static function emptyInputs(): array {
		return [
			'no signature' => ['tok-1', ''],
			'no token' => ['', 'whatever'],
		];
	}

	#[DataProvider('emptyInputs')]
	public function testNothingVerifiesWithoutATokenAndASignature(string $token, string $signature): void {
		$this->assertFalse($this->signer()->verify($token, $signature));
	}

	/** An empty token signed is still refused: there is no queue entry it could name. */
	public function testTheSignatureOfAnEmptyTokenIsRefused(): void {
		$signer = $this->signer();

		$this->assertFalse($signer->verify('', $signer->sign('')));
	}

	public function testAnUpperCasedSignatureIsNotTheSameSignature(): void {
		$signer = $this->signer();

		$this->assertFalse($signer->verify('tok-1', strtoupper($signer->sign('tok-1'))));
	}
}
