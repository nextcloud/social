<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

/**
 * The rotation keys a DID that moves here is given (§13.1): the person's
 * own first — their recovery key here, then every key the DID lists now
 * that is not the old server's — and this server's last. A key ranked
 * higher can undo, for 72 hours, an operation a lower one signed, so the
 * person can always overrule this server.
 */
final class RotationKeys {
	/** the most rotation keys a did:plc may list */
	public const MAX = 5;

	/**
	 * @param mixed $listed the DID's rotation keys now, as the directory has them
	 * @param list<string> $oldServer the old server's own keys, left out
	 * @param string $recovery the person's recovery key here, '' for none
	 * @param string $here this server's rotation key
	 * @return list<string>
	 */
	public static function forMoveHere(mixed $listed, array $oldServer, string $recovery, string $here): array {
		$own = $recovery !== '' ? [$recovery] : [];
		foreach (is_array($listed) ? $listed : [] as $key) {
			if (is_string($key) && str_starts_with($key, 'did:key:') && $key !== $here
				&& !in_array($key, $oldServer, true) && !in_array($key, $own, true)) {
				$own[] = $key;
			}
		}

		return [...array_slice($own, 0, self::MAX - 1), $here];
	}

	/**
	 * The did:keys of a list a server answered.
	 *
	 * @return list<string>
	 */
	public static function didKeys(mixed $listed): array {
		return array_values(array_filter(is_array($listed) ? $listed : [], static fn ($key): bool => is_string($key) && str_starts_with($key, 'did:key:')));
	}
}
