<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

/**
 * Produces `rsa-pss-sha512` signatures (RFC 9421 §3.3.1: SHA-512, MGF1 with
 * SHA-512, 64-byte salt) for tests, on any PHP this app supports.
 *
 * `openssl_sign()` only takes a padding argument from PHP 8.5, so the EMSA-PSS
 * encoding of RFC 8017 §9.1.1 is done here and OpenSSL performs the raw RSA
 * operation. This is the signer's side of what
 * `HttpMessageSignatureParser::verify()` checks; the RFC's own test vector is
 * what proves the verifier, and (on PHP 8.5) OpenSSL's native PSS verifier is
 * what proves this signer.
 */
final class RsaPssSigner {
	public static function sign(string $message, string $privateKeyPem): string {
		$key = openssl_pkey_get_private($privateKeyPem);
		$bits = (int)openssl_pkey_get_details($key)['bits'];
		$hashLength = 64;
		$saltLength = 64;
		$emBits = $bits - 1;
		$emLength = intdiv($emBits + 7, 8);

		$salt = random_bytes($saltLength);
		$hash = hash('sha512', str_repeat("\0", 8) . hash('sha512', $message, true) . $salt, true);
		$db = str_repeat("\0", $emLength - $saltLength - $hashLength - 2) . "\x01" . $salt;
		$maskedDb = $db ^ self::mgf1($hash, $emLength - $hashLength - 1);
		$maskedDb[0] = chr(ord($maskedDb[0]) & (0xff >> (8 * $emLength - $emBits)));

		$encoded = str_pad($maskedDb . $hash . "\xbc", intdiv($bits + 7, 8), "\0", STR_PAD_LEFT);
		openssl_private_encrypt($encoded, $signature, $key, OPENSSL_NO_PADDING);

		return $signature;
	}

	private static function mgf1(string $seed, int $length): string {
		$mask = '';
		for ($counter = 0; strlen($mask) < $length; $counter++) {
			$mask .= hash('sha512', $seed . pack('N', $counter), true);
		}

		return substr($mask, 0, $length);
	}
}
