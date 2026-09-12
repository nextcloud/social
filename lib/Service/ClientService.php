<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Db\ClientAuthRequest;
use OCA\Social\Db\ClientRequest;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Security\SecretHasher;
use OCA\Social\Tools\Traits\TStringTools;

/**
 * Class ClientService
 *
 * @package OCA\Social\Service
 */
class ClientService {
	public const TIME_TOKEN_REFRESH = 300; // 5m
	//	const TIME_TOKEN_TTL = 21600; // 6h
	//	const TIME_AUTH_TTL = 30672000; // 1y

	// looks like there is no token refresh. token must have been used in the last year.
	public const TIME_TOKEN_TTL = 30672000; // 1y

	// an authorization code is single-use plumbing; it expires quickly
	public const TIME_CODE_TTL = 600; // 10m

	use TStringTools;

	private ClientRequest $clientRequest;

	private SecretHasher $secretHasher;

	private MiscService $miscService;

	public function __construct(
		ClientRequest $clientRequest,
		SecretHasher $secretHasher,
		MiscService $miscService,
		private ClientAuthRequest $clientAuthRequest,
	) {
		$this->clientRequest = $clientRequest;
		$this->secretHasher = $secretHasher;
		$this->miscService = $miscService;
	}

	/**
	 * @param SocialClient $client
	 *
	 * @throws ClientException
	 */
	public function createApp(SocialClient $client): void {
		if ($client->getAppName() === '') {
			throw new ClientException('missing client_name');
		}

		if (empty($client->getAppRedirectUris())) {
			throw new ClientException('missing redirect_uris');
		}

		$client->setAppClientId($this->token(40));
		$client->setAppClientSecret($this->token(40));

		$this->clientRequest->saveApp($client);
	}

	/**
	 * Records that this account has authorized this app, and returns the code
	 * to hand back.
	 *
	 * One row per (app, account): an app registration used to hold a single
	 * authorization in its own row, so the second person to sign in with a
	 * client signed the first one out. Re-authorizing replaces that account's
	 * row and nobody else's.
	 */
	public function authClient(SocialClient $client): void {
		$client->setAuthCode($this->token(60));

		$this->clientAuthRequest->authorize(
			$client->getId(),
			$client->getAuthUserId(),
			$client->getAuthAccount(),
			$client->getAuthScopes(),
			$client->getAuthCode()
		);
	}

	/**
	 * Exchanges an authorization code for a token.
	 *
	 * The code decides whose authorization this is, so what comes back is that
	 * account's — not whatever the app row last held.
	 *
	 * @throws ClientNotFoundException the code names no live authorization
	 * @throws ClientException it names one that has expired
	 */
	public function exchangeCode(SocialClient $client, string $code): SocialClient {
		$authorized = $this->clientAuthRequest->getByCode($client->getId(), $code);

		// authorize() stamps last_update at the authorization moment
		if ($authorized->getLastUpdate() > 0
			&& $authorized->getLastUpdate() + self::TIME_CODE_TTL < time()) {
			throw new ClientException('code expired');
		}

		return $this->clientAuthRequest->exchange($client->getId(), $code, $this->token(80));
	}

	/** What one account has authorized. @return SocialClient[] */
	public function getAuthorizationsOf(string $userId): array {
		return $this->clientAuthRequest->getByUser($userId);
	}

	/**
	 * @param string $clientId
	 *
	 * @return SocialClient
	 * @throws ClientNotFoundException
	 */
	public function getFromClientId(string $clientId): SocialClient {
		return $this->clientRequest->getFromClientId($clientId);
	}

	/**
	 * @param string $token
	 *
	 * @return SocialClient
	 * @throws ClientNotFoundException
	 */
	public function getFromToken(string $token): SocialClient {
		$client = $this->clientAuthRequest->getByToken($token);

		if ($client->getLastUpdate() + self::TIME_TOKEN_TTL < time()) {
			try {
				// only the authorization goes: the app registration is the
				// instance's, and taking it with an idle token made the client
				// register itself all over again
				$this->clientAuthRequest->deprecate();
			} catch (Exception $e) {
			}

			throw new ClientNotFoundException();
		}

		// Keep the row's last_update roughly current (at most one write per
		// TIME_TOKEN_REFRESH), so a token in active use never reaches the TTL.
		// The old inverted comparison only refreshed *recently written* rows, so
		// any token idle for five minutes stopped refreshing and died a year
		// after its first burst of use, no matter how actively it was used since.
		if ($client->getLastUpdate() + self::TIME_TOKEN_REFRESH < time()) {
			$this->clientAuthRequest->touch($client->getAuthId());
		}

		return $client;
	}

	/**
	 * Revokes the access token presented by a client (RFC 7009). Unknown tokens
	 * are not an error — the outcome the caller asked for is true either way.
	 *
	 * @throws ClientException
	 */
	public function revokeToken(SocialClient $client, string $token): void {
		$stored = $this->clientAuthRequest->getByToken($token);
		if ($stored->getId() !== $client->getId()) {
			throw new ClientException('token does not belong to this client');
		}

		// one authorization, not the app row: revoking on one device must not
		// sign out everybody else who authorized the same client
		$this->clientAuthRequest->revoke($stored->getAuthId());
	}

	/**
	 * @param SocialClient $client
	 * @param array $data
	 *
	 * @throws ClientException
	 */
	public function confirmData(SocialClient $client, array $data) {
		if (array_key_exists('redirect_uri', $data)
			&& !in_array($data['redirect_uri'], $client->getAppRedirectUris())) {
			throw new ClientException('unknown redirect_uri');
		}

		if (array_key_exists('client_secret', $data)
			&& !$this->secretHasher->matches($client->getAppClientSecret(), (string)$data['client_secret'])) {
			throw new ClientException('wrong client_secret');
		}

		if (array_key_exists('app_scopes', $data)) {
			$scopes = $data['app_scopes'];
			if (!is_array($scopes)) {
				$scopes = $client->getScopesFromString($scopes);
			}

			foreach ($scopes as $scope) {
				if (!in_array($scope, $client->getAppScopes())) {
					throw new ClientException('invalid scope');
				}
			}
		}

		if (array_key_exists('auth_scopes', $data)) {
			$scopes = $data['auth_scopes'];
			if (!is_array($scopes)) {
				$scopes = $client->getScopesFromString($scopes);
			}

			foreach ($scopes as $scope) {
				if (!in_array($scope, $client->getAuthScopes())) {
					throw new ClientException('invalid scope');
				}
			}
		}

		// `code` is not among what this checks any more: an app row no longer
		// carries one, because it no longer carries one authorization. The code
		// is what *finds* the authorization, so it is checked by
		// exchangeCode() against the row it names.
	}
}
