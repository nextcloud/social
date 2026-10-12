<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

/**
 * The Akkoma on the other side, read through its Mastodon-compatible client
 * API, with Pleroma's emoji reactions (`/api/v1/pleroma/statuses/…/reactions`)
 * on top.
 */
class Akkoma extends ClientApi {
	public function __construct(
		string $baseUrl,
		private string $token,
		private string $username = 'interop',
	) {
		parent::__construct($baseUrl);
	}

	/** Whether this suite has an Akkoma to talk to at all. */
	public static function fromEnvironment(): ?self {
		$base = (string)getenv('AKKOMA_BASE_URL');
		$token = (string)getenv('AKKOMA_TOKEN');
		if ($base === '' || $token === '') {
			return null;
		}

		return new self($base, $token);
	}

	/** The handle of the account this client acts as, `user@host`. */
	public function handle(): string {
		return $this->username . '@' . (string)parse_url($this->baseUrl, PHP_URL_HOST);
	}

	/** Reacts to a status with one emoji, Pleroma's way. */
	public function react(string $statusId, string $emoji): void {
		$this->put('/api/v1/pleroma/statuses/' . rawurlencode($statusId) . '/reactions/' . rawurlencode($emoji));
	}

	#[\Override]
	public function name(): string {
		return 'Akkoma (' . $this->username . ')';
	}

	#[\Override]
	protected function authorization(): string {
		return 'Bearer ' . $this->token;
	}

	#[\Override]
	protected function headers(): array {
		return array_merge(parent::headers(), ['User-Agent: aloha-social-interop']);
	}
}
