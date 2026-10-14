<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Identity;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\Mnemonic;
use OCA\Social\Atproto\Protocol\Encoding;
use PHPUnit\Framework\TestCase;

/**
 * The recovery phrase as other tools have to read it: the derivation the
 * documentation spells out, and the key written the ways goat and the
 * reference PDS take one.
 */
class MnemonicTest extends TestCase {
	private const PHRASE = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
	private const HEX = '950f8acc1852f1a2562b2851457a04b1ba22541dd0f3b95ab9302d2894f29397';
	private const MULTIBASE = 'z3vLe2mABz9GSzoARHpVNW8P1rnySJ8rmfg3AKSpyVfHE526';
	private const DID_KEY = 'did:key:zQ3shX448VC3xo912wY7zzdUFdMqGifmFTv9GomCodivqkgkt';

	/** sixteen zero bytes: SHA-256 of the prefix and the entropy, as the documentation gives it */
	public function testTheKeyIsTheHashOfThePrefixAndTheEntropy(): void {
		$this->assertSame(hash('sha256', 'social.atproto.recovery.v1' . str_repeat("\0", 16)), self::HEX);
		$this->assertSame(['did_key' => self::DID_KEY, 'multibase' => self::MULTIBASE, 'hex' => self::HEX], Mnemonic::keyForms(self::PHRASE));
	}

	/** the multicodec secp256k1-priv (0x1301, varint 0x81 0x26) and the 32 secret bytes, base58btc */
	public function testTheMultibaseKeyIsTheSecretAfterItsMulticodec(): void {
		$key = PrivateKey::generate(Curve::K256);
		$decoded = Encoding::base58Decode(substr($key->multibase(), 1));

		$this->assertStringStartsWith('z', $key->multibase());
		$this->assertSame("\x81\x26" . $key->secret(), $decoded);
		$this->assertSame("\x86\x26", Curve::P256->privateKeyPrefix());
	}

	public function testTheFormsOfAGeneratedPhraseAreItsKey(): void {
		[$phrase, $key] = Mnemonic::generate();

		$this->assertSame(['did_key' => $key->didKey(), 'multibase' => $key->multibase(), 'hex' => bin2hex($key->secret())], Mnemonic::keyForms($phrase));
	}

	/** The documentation carries the same example, so a reimplementation can check itself against it. */
	public function testTheDocumentationGivesTheSameExample(): void {
		$doc = (string)file_get_contents(__DIR__ . '/../../../docs/Atproto-Compatibility.md');

		foreach ([self::PHRASE, self::HEX, self::MULTIBASE, self::DID_KEY, 'social.atproto.recovery.v1'] as $value) {
			$this->assertStringContainsString($value, $doc);
		}
	}

	/** The script shipped for deriving the key offline gives the key this class does. */
	public function testTheOfflineScriptDerivesTheSameKey(): void {
		$python = trim((string)shell_exec('command -v python3 2>/dev/null'));
		if ($python === '') {
			$this->markTestSkipped('python3 is not installed');
		}
		[$phrase, $key] = Mnemonic::generate();
		$resources = __DIR__ . '/../../../resources/atproto/';

		$process = proc_open([$python, $resources . 'recovery-key.py', $resources . 'bip39-english.txt'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$this->assertIsResource($process);
		fwrite($pipes[0], strtoupper($phrase) . "\n");
		fclose($pipes[0]);
		$output = (string)stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$this->assertStringContainsString('hex:       ' . bin2hex($key->secret()), $output);
		$this->assertStringContainsString('multibase: ' . $key->multibase(), $output);
	}
}
