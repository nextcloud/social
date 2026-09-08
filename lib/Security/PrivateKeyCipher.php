<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Security;

use Exception;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Encrypts actor private keys at rest with the instance secret (ICrypto), so a
 * database dump alone is no longer enough to impersonate every local actor.
 *
 * Rows written before encryption existed hold the bare PEM; those are
 * recognised by their '-----BEGIN' prefix and still readable, and the
 * EncryptPrivateKeys repair step rewrites them once.
 */
class PrivateKeyCipher {
	private const PEM_PREFIX = '-----BEGIN';

	private ICrypto $crypto;
	private LoggerInterface $logger;

	public function __construct(ICrypto $crypto, LoggerInterface $logger) {
		$this->crypto = $crypto;
		$this->logger = $logger;
	}

	/**
	 * The value to store for a private key.
	 */
	public function seal(string $privateKey): string {
		if ($privateKey === '') {
			return '';
		}

		return $this->crypto->encrypt($privateKey);
	}

	/**
	 * The private key held in a stored value — sealed, legacy plaintext or empty.
	 * An undecryptable value (the instance secret changed) yields '' so signing
	 * fails visibly instead of sending garbage signatures.
	 */
	public function open(string $stored): string {
		if ($stored === '' || $this->isPlain($stored)) {
			return $stored;
		}

		try {
			return $this->crypto->decrypt($stored);
		} catch (Exception $e) {
			$this->logger->error('Social: cannot decrypt an actor private key', ['exception' => $e]);

			return '';
		}
	}

	public function isPlain(string $stored): bool {
		return str_starts_with($stored, self::PEM_PREFIX);
	}
}
