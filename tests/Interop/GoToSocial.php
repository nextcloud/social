<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

/**
 * The GoToSocial on the other side, read through its own client API.
 *
 * GoToSocial's client API is Mastodon's, with what only GoToSocial has on
 * top: an `interaction_policy` on every status, and the interaction requests
 * a post's author approves or rejects (`/api/v1/interaction_requests`).
 *
 * One instance of this per GoToSocial account: the token decides who is
 * asking.
 */
class GoToSocial extends ClientApi {
	public function __construct(
		string $baseUrl,
		private string $token,
		private string $username,
	) {
		parent::__construct($baseUrl);
	}

	/**
	 * Whether this suite has a GoToSocial to talk to at all, as the account
	 * the workflow made a token for in `$tokenVariable`.
	 *
	 * `GOTOSOCIAL_TOKEN` is `interop`, the account most tests act as;
	 * `GOTOSOCIAL_TOKEN_GUARDED` is `guarded`, which is locked — as every
	 * GoToSocial account is until its owner says otherwise.
	 */
	public static function fromEnvironment(string $tokenVariable = 'GOTOSOCIAL_TOKEN', string $username = 'interop'): ?self {
		$values = Environment::values('GoToSocial', 'GOTOSOCIAL_BASE_URL', $tokenVariable);
		if ($values === null) {
			return null;
		}

		return new self($values['GOTOSOCIAL_BASE_URL'], $values[$tokenVariable], $username);
	}

	/** The GoToSocial host, as it appears in a handle. */
	public static function host(): string {
		$host = (string)getenv('GOTOSOCIAL_HOST');

		return ($host !== '') ? $host : (string)parse_url((string)getenv('GOTOSOCIAL_BASE_URL'), PHP_URL_HOST);
	}

	/** The handle of the account this client acts as, `user@host`. */
	public function handle(): string {
		return $this->username . '@' . self::host();
	}

	/** The ActivityPub id of the account this client acts as. */
	public function actorUri(): string {
		return 'https://' . self::host() . '/users/' . $this->username;
	}

	/** GoToSocial's id of the account this client acts as. */
	public function ownId(): string {
		return (string)($this->get('/api/v1/accounts/verify_credentials')['id'] ?? '');
	}

	/**
	 * The interactions with this account's posts that wait for its answer:
	 * replies, favourites and boosts its interaction policy did not let
	 * through on their own.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function interactionRequests(): array {
		return $this->listOf($this->get('/api/v1/interaction_requests', ['limit' => '40']));
	}

	/**
	 * Waits for an interaction request that satisfies a condition.
	 *
	 * @param callable(array<string, mixed>): bool $condition
	 * @return array<string, mixed>|null
	 */
	public function awaitInteractionRequest(callable $condition): ?array {
		return $this->await(function () use ($condition): ?array {
			foreach ($this->interactionRequests() as $request) {
				if ($condition($request)) {
					return $request;
				}
			}

			return null;
		});
	}

	/**
	 * The status a reply is filed under, by GoToSocial's id, as its thread
	 * shows it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function descendants(string $id): array {
		$context = $this->get('/api/v1/statuses/' . rawurlencode($id) . '/context');

		return $this->listOf(is_array($context['descendants'] ?? null) ? $context['descendants'] : []);
	}

	#[\Override]
	public function name(): string {
		return 'GoToSocial (' . $this->username . ')';
	}

	#[\Override]
	protected function authorization(): string {
		return 'Bearer ' . $this->token;
	}

	/** GoToSocial answers a request without a `User-Agent` with a 418. */
	#[\Override]
	protected function headers(): array {
		return array_merge(parent::headers(), ['User-Agent: aloha-social-interop']);
	}
}
