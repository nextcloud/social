<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;

/**
 * Mastodon's `Admin::DomainBlock`: one entry of the instance-wide access list
 * that `FediverseService` keeps — not the per-account domain blocks of
 * `/api/v1/domain_blocks`, which are one user hiding a server from themselves.
 *
 * The access list stores a hostname and nothing else, so most of the entity is
 * the empty value of its type rather than an invented one:
 *
 *  - `severity` is always `suspend`. An entry on the block list refuses the
 *    domain outright — `FediverseService::authorized()` throws before anything
 *    is read or delivered — and there is no lesser setting to report. It is
 *    also why `reject_media` and `reject_reports` are `true`: nothing from the
 *    domain is accepted, media and reports included.
 *  - `private_comment` and `public_comment` are `null`: the list has no room
 *    for a reason, so there is none to show.
 *  - `obfuscate` is `false`: this instance publishes no list of what it
 *    blocks, so there is nothing to obfuscate in one.
 *  - `created_at` is the epoch. The list stores no timestamps, and the epoch
 *    is how this API says "not recorded" in a field Mastodon declares as a
 *    date and clients decode as one — it does not mean the domain was blocked
 *    in 1970.
 *
 * `id` is derived from the domain rather than stored: the first eight hex
 * digits of its md5, as a decimal string, so it is numeric the way every other
 * id on this API is, stable for as long as the entry names the same domain,
 * and unchanged by other entries being added or removed — which a position in
 * the list would not be. Routes that take an id accept the domain itself too.
 */
class AdminDomainBlock implements JsonSerializable {
	public const SEVERITY = 'suspend';

	public function __construct(
		private string $domain = '',
	) {
	}

	public function getDomain(): string {
		return $this->domain;
	}

	public function getId(): string {
		return self::idOf($this->domain);
	}

	/** Whether an id or a domain names this entry. */
	public function isNamedBy(string $reference): bool {
		$reference = strtolower(trim($reference));

		return ($reference !== '')
			&& (($reference === strtolower($this->domain)) || ($reference === $this->getId()));
	}

	public static function idOf(string $domain): string {
		return (string)hexdec(substr(md5(strtolower(trim($domain))), 0, 8));
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'domain' => $this->domain,
			'created_at' => gmdate('Y-m-d\TH:i:s', 0) . '.000Z',
			'severity' => self::SEVERITY,
			'reject_media' => true,
			'reject_reports' => true,
			'private_comment' => null,
			'public_comment' => null,
			'obfuscate' => false,
		];
	}
}
