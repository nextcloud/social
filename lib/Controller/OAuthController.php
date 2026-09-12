<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\InstanceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

class OAuthController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private InstanceService $instanceService,
		private AccountService $accountService,
		private ClientService $clientService,
		private ConfigService $configService,
		private LoggerInterface $logger,
		private IInitialState $initialState,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * What this software is called on NodeInfo. The schema wants
	 * `^[a-z0-9-]+$`, and crawlers group instances by this string, so it is a
	 * constant: the theming title that used to go here put every Nextcloud
	 * under its own "software" and made the app invisible in every statistic.
	 * The human title travels as `metadata.nodeName`.
	 */
	private const SOFTWARE_NAME = 'nextcloud-social';
	private const REPOSITORY = 'https://github.com/nextcloud/social';

	/** `/.well-known/nodeinfo/2.0` */
	#[NoCSRFRequired]
	#[PublicPage]
	public function nodeinfo2(): Response {
		return new DataResponse($this->nodeInfo('2.0'), Http::STATUS_OK);
	}

	/**
	 * `/.well-known/nodeinfo/2.1`: 2.0 plus `software.repository` and
	 * `software.homepage`. Needs a route and a link from the discovery
	 * document (`WebfingerHandler::handleNodeInfo()`) to be reachable.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function nodeinfo21(): Response {
		return new DataResponse($this->nodeInfo('2.1'), Http::STATUS_OK);
	}

	/**
	 * The NodeInfo document for one schema version. The schema allows no
	 * property it does not name, so `services` and `metadata` are always
	 * present (both required) and nothing else is added at the top level.
	 */
	private function nodeInfo(string $schema): array {
		try {
			$local = $this->instanceService->getLocal();
			$name = $local->getTitle();
			$description = $local->getShortDescription();
			$version = $local->getVersion();
			$usage = $local->getUsage();
			$openReg = $local->isRegistrations();
		} catch (InstanceDoesNotExistException $e) {
			$name = 'Nextcloud Social';
			$description = '';
			$version = $this->configService->getAppValue('installed_version');
			$usage = [];
			$openReg = false;
		}

		$software = [
			'name' => self::SOFTWARE_NAME,
			'version' => (string)$version,
		];
		if ($schema === '2.1') {
			$software['repository'] = self::REPOSITORY;
			$software['homepage'] = self::REPOSITORY;
		}

		return [
			'version' => $schema,
			'software' => $software,
			'protocols' => ['activitypub'],
			// no third-party service is bridged in or out
			'services' => ['inbound' => [], 'outbound' => []],
			'usage' => $usage,
			'openRegistrations' => $openReg,
			'metadata' => [
				'nodeName' => $name,
				'nodeDescription' => $description,
			],
		];
	}

	/**
	 * @AnonRateThrottle(limit=15, period=300)
	 *
	 * @param array|string $redirect_uris
	 *
	 * @throws ClientException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function apps(
		string $client_name = '',
		$redirect_uris = '',
		string $website = '',
		string $scopes = 'read',
	): DataResponse {
		// Mastodon's own API takes several redirect URIs, newline-separated in a
		// single `redirect_uris` field. Wrapping the whole block into one entry
		// meant `ClientService::confirmData()` compared a single URI against a
		// string holding all of them, so a client registered with more than one
		// could never authorize with any of them.
		if (!is_array($redirect_uris)) {
			$redirect_uris = preg_split('/\r\n|\r|\n/', (string)$redirect_uris);
		}

		$redirect_uris = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn ($uri): string => trim((string)$uri),
						$redirect_uris
					),
					static fn (string $uri): bool => $uri !== ''
				)
			)
		);

		$client = new SocialClient();
		$client->setAppWebsite($website);
		$client->setAppRedirectUris($redirect_uris);
		$client->setAppScopes($client->getScopesFromString($scopes));
		$client->setAppName($client_name);

		$this->clientService->createApp($client);

		return new DataResponse(
			[
				'id' => $client->getId(),
				'name' => $client->getAppName(),
				'website' => $client->getAppWebsite(),
				'scopes' => implode(' ', $client->getAppScopes()),
				'client_id' => $client->getAppClientId(),
				'client_secret' => $client->getAppClientSecret(),
				// Mastodon's Application entity always carries these two, and a
				// client that decodes the answer into a typed struct fails
				// without them. `redirect_uri` is the first of what was
				// registered, which is what Mastodon reports; `vapid_key` is
				// empty because there is no Web Push here to have a key for.
				'redirect_uri' => $client->getAppRedirectUris()[0] ?? '',
				'vapid_key' => '',
			], Http::STATUS_OK
		);
	}

	/**
	 * The consent page.
	 *
	 * Everything that can be wrong with the request — an unknown `client_id`, a
	 * `response_type` that is not `code`, a `redirect_uri` or scope the client
	 * never registered — is answered the way `authorizing()` answers it. Left
	 * to escape, each of them reached the browser as a Nextcloud HTML error
	 * page (with a stack trace where debug is on) rather than as something the
	 * client can read.
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function authorize(
		string $client_id,
		string $redirect_uri,
		string $response_type,
		string $scope = 'read',
		string $state = '',
	): Response {
		try {
			$user = $this->userSession->getUser();

			// check actor exists
			$this->accountService->getActorFromUserId($user->getUID());

			if ($response_type !== 'code') {
				throw new ClientNotFoundException('invalid response type');
			}

			// check client exists in db
			$client = $this->clientService->getFromClientId($client_id);
			// A code must only ever travel to a URI the client registered; checked before
			// the consent page exists, so there is nothing to confirm on a forged link.
			$this->clientService->confirmData(
				$client,
				[
					'app_scopes' => $scope,
					'redirect_uri' => $redirect_uri
				]
			);
			$this->initialState->provideInitialState('appName', $client->getAppName());

			return new TemplateResponse(Application::APP_ID, 'oauth2', [
				'request'
					=> [
						'clientId' => $client_id,
						'redirectUri' => $redirect_uri,
						'responseType' => $response_type,
						'scope' => $scope,
						// carried through the consent form so the POST can echo it
						'state' => $state
					]
			]);
		} catch (Throwable $e) {
			$this->logger->notice($e->getMessage() . ' ' . get_class($e));

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function authorizing(
		string $client_id,
		string $redirect_uri,
		string $response_type,
		string $scope = 'read',
		string $state = '',
	): Response {
		try {
			$user = $this->userSession->getUser();
			$account = $this->accountService->getActorFromUserId($user->getUID());

			if ($response_type !== 'code') {
				throw new ClientNotFoundException('invalid response type');
			}

			$client = $this->clientService->getFromClientId($client_id);
			$this->clientService->confirmData(
				$client,
				[
					'app_scopes' => $scope,
					'redirect_uri' => $redirect_uri
				]
			);

			$client->setAuthScopes($client->getScopesFromString($scope));
			$client->setAuthAccount($account->getPreferredUsername());
			$client->setAuthUserId($user->getUID());

			$this->clientService->authClient($client);
			$code = $client->getAuthCode();

			if ($redirect_uri !== 'urn:ietf:wg:oauth:2.0:oob') {
				return new RedirectResponse($this->redirectWithCode($redirect_uri, $code, $state));
			}

			// the out-of-band flow: the code is shown to the person to paste
			// into their client, so it comes back as the response body
			$result = ['code' => $code];
			if ($state !== '') {
				$result['state'] = $state;
			}

			return new DataResponse($result, Http::STATUS_OK);
		} catch (Exception $e) {
			$this->logger->notice($e->getMessage() . ' ' . get_class($e));

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * The registered redirect URI with the authorization code — and the
	 * client's `state` — appended to whatever query string it already had.
	 *
	 * Built with http_build_query rather than by concatenating '?code=': a
	 * `redirect_uri` that already carries a query string (Elk registers one)
	 * ended up with two `?` in it and the client could not read the code out of
	 * it. `state` was dropped entirely, which is the value a web client
	 * compares against what it stored to know the redirect is the answer to its
	 * own request — a client that follows the spec rejects a redirect without
	 * it, and one that does not is open to having a code injected.
	 */
	private function redirectWithCode(string $redirectUri, string $code, string $state): string {
		$parameters = ['code' => $code];
		if ($state !== '') {
			$parameters['state'] = $state;
		}

		$fragment = '';
		$pos = strpos($redirectUri, '#');
		if ($pos !== false) {
			$fragment = substr($redirectUri, $pos);
			$redirectUri = substr($redirectUri, 0, $pos);
		}

		$separator = (strpos($redirectUri, '?') === false) ? '?' : '&';

		return $redirectUri . $separator . http_build_query($parameters) . $fragment;
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	#[BruteForceProtection(action: 'socialOauthToken')]
	public function token(
		string $client_id,
		string $client_secret,
		string $redirect_uri,
		string $grant_type,
		string $scope = 'read',
		string $code = '',
	): DataResponse {
		try {
			$client = $this->clientService->getFromClientId($client_id);
			$this->clientService->confirmData(
				$client,
				[
					'client_secret' => $client_secret,
					'redirect_uri' => $redirect_uri,
					'auth_scopes' => $scope
				]
			);

			if ($grant_type === 'authorization_code') {
				if ($code === '') {
					return new DataResponse(['error' => 'missing code'], Http::STATUS_BAD_REQUEST);
				}

				$this->clientService->confirmData($client, ['code' => $code]);
				$this->clientService->generateToken($client);
			} elseif ($grant_type === 'client_credentials') {
				// Falling through would return the token column of the client row —
				// whatever token the last user's authorization-code grant put there.
				return new DataResponse(
					['error' => 'unsupported_grant_type'], Http::STATUS_BAD_REQUEST
				);
			} else {
				return new DataResponse(
					['error' => 'invalid value for grant_type'], Http::STATUS_BAD_REQUEST
				);
			}

			if ($client->getToken() === '') {
				return new DataResponse(
					['error' => 'issue generating access_token'], Http::STATUS_BAD_REQUEST
				);
			}

			return new DataResponse(
				[
					// the scopes this token really carries, which is what
					// checkTokenScope() enforces on every request made with it.
					// Echoing the scope of the token *call* — 'read', for a
					// client like Tusky that omits it — had clients hiding
					// their compose button while writes in fact worked.
					'access_token' => $client->getToken(),
					'token_type' => 'Bearer',
					'scope' => implode(' ', $client->getAuthScopes()),
					'created_at' => $client->getCreation()
				], Http::STATUS_OK
			);
		} catch (ClientNotFoundException $e) {
			// A wrong client id / secret / code is a credential guess; throttle it so
			// the public token endpoint cannot be brute-forced.
			$response = new DataResponse(['error' => 'unknown client_id'], Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => 'socialOauthToken']);

			return $response;
		} catch (Exception $e) {
			$response = new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => 'socialOauthToken']);

			return $response;
		}
	}

	/**
	 * Token revocation (RFC 7009). Only the client the token was issued to may
	 * revoke it. Always answers 200 for a token that (no longer) exists, so the
	 * endpoint is not an oracle; wrong client credentials are throttled like the
	 * token endpoint.
	 *
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	#[BruteForceProtection(action: 'socialOauthToken')]
	public function revoke(string $client_id, string $client_secret, string $token): DataResponse {
		try {
			$client = $this->clientService->getFromClientId($client_id);
			$this->clientService->confirmData($client, ['client_secret' => $client_secret]);
		} catch (Exception $e) {
			$response = new DataResponse(['error' => 'unknown client_id'], Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => 'socialOauthToken']);

			return $response;
		}

		try {
			$this->clientService->revokeToken($client, $token);
		} catch (Exception $e) {
			// RFC 7009: an unknown or already-revoked token is a success
		}

		return new DataResponse([], Http::STATUS_OK);
	}
}
