<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Security;

/**
 * OAuth client secrets, authorization codes and access tokens are stored as
 * `sha256:<hex>` digests, so a database read no longer hands out working
 * credentials. The values are high-entropy random tokens, so a fast unsalted
 * digest is the right tool — it also keeps the token lookup a plain indexed
 * equality query.
 *
 * Rows written before hashing existed hold the bare value; `matches()` and
 * `forLookup()` still understand them, and the HashClientSecrets repair step
 * rewrites them once.
 */
class SecretHasher {
	private const PREFIX = 'sha256:';

	public function hash(string $secret): string {
		if ($secret === '') {
			return '';
		}

		return self::PREFIX . hash('sha256', $secret);
	}

	public function isHashed(string $stored): bool {
		return str_starts_with($stored, self::PREFIX);
	}

	/**
	 * Whether a presented secret matches the stored value, hashed or legacy plaintext.
	 */
	public function matches(string $stored, string $presented): bool {
		if ($stored === '' || $presented === '') {
			return false;
		}

		if ($this->isHashed($stored)) {
			return hash_equals($stored, $this->hash($presented));
		}

		return hash_equals($stored, $presented);
	}

	/**
	 * The values a presented secret may be stored under — for looking a token up
	 * while legacy plaintext rows can still exist.
	 *
	 * A presented secret that already carries the hash prefix is never looked up
	 * as a legacy row: a legacy row holds the bare value, and the prefixed shape
	 * is what the column itself holds. Offering it here made the stored digest a
	 * working credential of its own, so a database dump, a backup or a read-only
	 * SQL flaw handed out usable tokens — the one thing hashing them is for.
	 *
	 * @return string[]
	 */
	public function forLookup(string $secret): array {
		if ($secret === '' || $this->isHashed($secret)) {
			return [];
		}

		return [$this->hash($secret), $secret];
	}
}
