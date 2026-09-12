<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Security;

/**
 * Decides whether an outbound request is allowed to reach a given host.
 *
 * Federation talks to arbitrary hosts on the public internet, so the app cannot
 * use an allowlist — but it must not be steered at the instance's own network.
 * Unless an administrator has opted in (`allow_local_remote_servers`), a host that
 * is, or resolves to, a private, loopback, link-local or otherwise reserved
 * address is refused. `169.254.169.254`, the cloud metadata endpoint, is reserved
 * and therefore covered.
 *
 * The classifier is pure so it can be tested without a network; the DNS lookups it
 * performs for a hostname are the only side effect.
 */
final class RemoteAddress {
	/**
	 * Whether a literal IP address is in a private or reserved range.
	 */
	public static function isLocalIp(string $ip): bool {
		if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
			return false;
		}

		// An IPv6 address that only wraps an IPv4 one is classified by the
		// address it wraps: IPv4-mapped (::ffff:a.b.c.d — PHP's range flags
		// only learned these in later PHP versions, so never rely on them)
		// and NAT64 (64:ff9b::/96, delivered to the embedded address by a
		// translating network).
		$packed = inet_pton($ip);
		if ($packed !== false && strlen($packed) === 16) {
			$hex = bin2hex($packed);
			if (str_starts_with($hex, '00000000000000000000ffff')
				|| str_starts_with($hex, '0064ff9b0000000000000000')) {
				return self::isLocalIp(inet_ntop(substr($packed, 12)));
			}
		}

		// A public address survives both flags; anything private or reserved
		// (10/8, 172.16/12, 192.168/16, 127/8, 169.254/16, ::1, fc00::/7, fe80::/10,
		// 0/8, 240/4, …) is filtered out and returns false here.
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
			return true;
		}

		// Multicast is not covered by the reserved-range flag; reject it too.
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
			return (int)explode('.', $ip)[0] >= 224;
		}

		return str_starts_with(strtolower($ip), 'ff');
	}

	/**
	 * Whether a host — a literal IP or a name — is, or resolves to, a local address.
	 *
	 * A name is refused if *any* of its resolved addresses is local, so a record
	 * that mixes a public and a loopback address cannot be used to slip through.
	 */
	public static function isLocalHost(string $host): bool {
		$host = trim($host, " \t\n\r\0\x0B[]");
		if ($host === '') {
			return true;
		}

		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return self::isLocalIp($host);
		}

		foreach (self::resolve($host) as $ip) {
			if (self::isLocalIp($ip)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Answers already given, for the life of this process.
	 *
	 * A delivery run resolves the same few dozen hosts over and over: one post
	 * to two hundred inboxes is two hundred DNS round-trips for a handful of
	 * distinct names, each one blocking the request behind it. Remembering the
	 * answers for the length of the run removes almost all of them.
	 *
	 * Deliberately per-process rather than a shared cache: a cron pass is
	 * where the repetition is, and a short-lived memo cannot serve a stale
	 * address to a later request.
	 *
	 * @var array<string, list<string>>
	 */
	private static array $resolved = [];

	/** Forgets the memo, for tests and long-running workers. */
	public static function forgetResolved(): void {
		self::$resolved = [];
	}

	/**
	 * @return list<string> every A and AAAA address the host resolves to
	 */
	private static function resolve(string $host): array {
		if (array_key_exists($host, self::$resolved)) {
			return self::$resolved[$host];
		}

		$ips = [];

		$v4 = gethostbynamel($host);
		if (is_array($v4)) {
			$ips = $v4;
		}

		$v6 = @dns_get_record($host, DNS_AAAA);
		if (is_array($v6)) {
			foreach ($v6 as $record) {
				if (isset($record['ipv6'])) {
					$ips[] = $record['ipv6'];
				}
			}
		}

		// A name that resolves to nothing is treated as local, i.e. refused: an
		// unresolvable target is not a legitimate Fediverse peer, and failing closed
		// is the safe direction.
		self::$resolved[$host] = ($ips === []) ? ['127.0.0.1'] : $ips;

		return self::$resolved[$host];
	}
}
