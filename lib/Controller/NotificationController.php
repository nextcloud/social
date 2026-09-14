<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\NotificationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The three notification routes that are about one notification rather than
 * about the list: reading one, dismissing one, and dismissing all of them.
 *
 * Without them "dismiss" is a button every client draws and nothing answers —
 * masto.js reports the 404 as a failed request and leaves the row where it
 * was — and a client that opens a notification from a push payload has no way
 * to fetch it.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]`, like `ApiController` and
 * `ConversationController`: a Mastodon client authenticates with a bearer
 * token and has no Nextcloud session or CSRF token to present. Every route
 * here resolves a viewer first — no token, no session, 401 — and a
 * notification that is not the viewer's is not found.
 */
class NotificationController extends Controller {
	private string $bearer = '';
	private ?SocialClient $client = null;
	private ?Person $viewer = null;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private AccountService $accountService,
		private ClientService $clientService,
		private NotificationService $notificationService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$authHeader = trim($this->request->getHeader('Authorization'));
		if (strpos($authHeader, ' ')) {
			[$authType, $authToken] = explode(' ', $authHeader);
			if (strtolower($authType) === 'bearer') {
				$this->bearer = $authToken;
			}
		}
	}

	/** One notification of the viewer's, as the notification timeline serves it. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/notifications/{id}', requirements: ['id' => '\\d+'])]
	public function get(int $id): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			return new DataResponse($this->notificationService->get($this->viewer, $id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Dismisses one notification and answers `{}`, as Mastodon does.
	 *
	 * Dismissing one that is already gone is not an error: the client is
	 * asking for a state that holds, and a 404 there would leave the row on
	 * screen in every client that redraws from the answer.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/notifications/{id}/dismiss', requirements: ['id' => '\\d+'])]
	public function dismiss(int $id): DataResponse {
		try {
			$this->initViewer(['write:notifications']);

			try {
				$this->notificationService->dismiss($this->viewer, $id);
			} catch (ItemNotFoundException $e) {
			}

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Dismisses every notification the viewer has, and answers `{}`. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/notifications/clear')]
	public function clear(): DataResponse {
		try {
			$this->initViewer(['write:notifications']);
			$this->notificationService->clear($this->viewer);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Resolves the viewer from the bearer token, or from the Nextcloud session
	 * when there is none — the same order `ApiController` uses, because the
	 * same clients call both.
	 *
	 * @param string[] $scopes any one of which satisfies a bearer token
	 *
	 * @throws ClientNotFoundException there is nobody to answer for
	 * @throws InsufficientScopeException the token is fine, its grant is not
	 */
	private function initViewer(array $scopes): void {
		try {
			$userId = $this->currentSession($scopes);
			$this->viewer = $this->accountService->getActorFromUserId($userId, true);
		} catch (InsufficientScopeException $e) {
			throw $e;
		} catch (Exception $e) {
			// a missing, stale or made-up token is ordinary internet noise and
			// is answered with a 401, not logged as a fault
			$this->logger->debug('[NotificationController] no usable credentials', [
				'exception' => $e->getMessage(),
			]);

			throw new ClientNotFoundException('the access_token was revoked');
		}
	}

	/**
	 * @param string[] $scopes
	 *
	 * @throws ClientNotFoundException
	 * @throws InsufficientScopeException
	 */
	private function currentSession(array $scopes): string {
		if ($this->bearer !== '') {
			$this->client = $this->clientService->getFromToken($this->bearer);
			$this->checkTokenScope($scopes);

			return $this->client->getAuthUserId();
		}

		$user = $this->userSession->getUser();
		if ($user !== null && $this->request->passesCSRFCheck()) {
			return $user->getUID();
		}

		throw new ClientNotFoundException('userId not defined');
	}

	/**
	 * A granular scope is satisfied by itself or by the broad scope that
	 * contains it: `write:notifications` by `write:notifications` or by
	 * `write`, and by nothing else — a token granted `write:statuses` may post
	 * as the account, not empty its notifications.
	 *
	 * @param string[] $accepted
	 *
	 * @throws InsufficientScopeException
	 */
	private function checkTokenScope(array $accepted): void {
		foreach ($accepted as $scope) {
			$broad = strstr($scope, ':', true);
			$broad = ($broad === false) ? $scope : $broad;

			foreach ($this->client->getAuthScopes() as $granted) {
				if ($granted === $scope || $granted === $broad) {
					return;
				}
			}
		}

		throw new InsufficientScopeException(
			'token scope does not allow this request (needs ' . implode(' or ', $accepted) . ')'
		);
	}

	/**
	 * A failure as a Mastodon client can act on it: `{"error": "..."}` with a
	 * status that says what to do about it. An unrecognised failure is a bug
	 * on this side, so it answers 500 and its message is not sent on — these
	 * are `#[PublicPage]` routes, and echoing getMessage() publishes whatever
	 * the failure happened to name.
	 */
	private function error(Throwable $e): DataResponse {
		if ($e instanceof InsufficientScopeException) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_FORBIDDEN,
				['WWW-Authenticate' => 'Bearer error="insufficient_scope"']
			);
		}

		if ($e instanceof ClientNotFoundException) {
			$message = trim($e->getMessage());

			return new DataResponse(
				['error' => ($message === '') ? 'the access_token is invalid' : $message],
				Http::STATUS_UNAUTHORIZED,
				['WWW-Authenticate' => 'Bearer error="invalid_token"']
			);
		}

		// a notification that is not there and one that is somebody else's are
		// one answer, with Mastodon's own wording: telling them apart would
		// say whether an id exists and whose it is
		if ($e instanceof ItemNotFoundException) {
			return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
		}

		$this->logger->error('[NotificationController] unexpected failure answering the client API', [
			'exception' => $e,
			'route' => (string)$this->request->getParam('_route', ''),
		]);

		return new DataResponse(
			['error' => 'internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}
}
