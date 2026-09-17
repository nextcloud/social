<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;

/**
 * One directory this instance will ask about people: a host, and which API it
 * speaks.
 *
 * The kind is not guessed from the host. Sniffing it would mean a request
 * before the request — `/nodeinfo` and then the search — for an answer that
 * changes about once in a server's lifetime, and a wrong guess is a source
 * that silently answers nothing.
 */
class DirectorySource implements JsonSerializable {
	/** This instance's own directory. Never asked over the network. */
	public const KIND_LOCAL = 'local';

	/**
	 * Mastodon's own API, which is what Pixelfed, Hometown, Glitch and most
	 * forks serve too: a public profile directory, and a public exact-handle
	 * lookup.
	 */
	public const KIND_MASTODON = 'mastodon';

	/** Misskey and its forks: `users/search`, a real substring search. */
	public const KIND_MISSKEY = 'misskey';

	/** Lemmy: `search` with `type_=Users`. */
	public const KIND_LEMMY = 'lemmy';

	public const KINDS = [
		self::KIND_LOCAL,
		self::KIND_MASTODON,
		self::KIND_MISSKEY,
		self::KIND_LEMMY,
	];

	public function __construct(
		private string $host,
		private string $kind,
		private string $label = '',
	) {
	}

	public function getHost(): string {
		return $this->host;
	}

	public function getKind(): string {
		return $this->kind;
	}

	/** What a reader is offered it as; the host when nobody named it. */
	public function getLabel(): string {
		return ($this->label === '') ? $this->host : $this->label;
	}

	public function isLocal(): bool {
		return $this->kind === self::KIND_LOCAL;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'host' => $this->host,
			'kind' => $this->kind,
			'label' => $this->getLabel(),
		];
	}
}
