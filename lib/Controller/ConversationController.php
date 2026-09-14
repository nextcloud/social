<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConversationService;
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
 * Mastodon's conversations: the direct timeline as one row per exchange
 * instead of one row per message.
 *
 * This is the screen Tusky, Ivory, Ice Cubes and Phanpy read direct messages
 * from — none of them draws `/api/v1/timelines/direct`, which serves the same
 * messages ungrouped. What a conversation *is* here, and why its id is the
 * nid of the thread root, is in ConversationService.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]`, like `ApiController` and
 * `ListController`, and for the same reason: a Mastodon client authenticates
 * with a bearer token and has no Nextcloud session or CSRF token to present,
 * so `#[NoAdminRequired]` would refuse every real caller before the handler
 * ran. Every route here requires a viewer itself — no token, no session, 401 —
 * so nothing is public in fact, and a conversation is somebody's private
 * correspondence.
 */
class ConversationController extends Controller {
	private string $bearer = '';
	private ?SocialClient $client = null;
	private ?Person $viewer = null;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private AccountService $accountService,
		private ClientService $clientService,
		private ConversationService $conversationService,
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

	/**
	 * A page of the viewer's conversations, the one with the newest message
	 * first, with the `Link` header masto.js reads its cursor from.
	 *
	 * The cursor is a message nid, not a conversation id: conversations are
	 * ordered by their newest message, and a conversation id — the thread root
	 * — does not move when a message arrives, so it cannot page.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/conversations')]
	public function index(
		int $limit = ConversationService::LIMIT,
		int $max_id = 0,
		int $min_id = 0,
		int $since_id = 0,
	): DataResponse {
		try {
			$this->initViewer();

			$page = $this->conversationService->getPage(
				$this->viewer, $limit, $max_id, $min_id, $since_id
			);

			return $this->paged($page['conversations'], $page['next'], $page['prev']);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Marks the conversation read, and answers with it — which is what
	 * Mastodon returns, so a client redraws the row from the answer rather
	 * than guessing what it now looks like.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	// `/api/v1/conversations/{id}` cannot read this as a conversation named
	// "4/read" because `{id}` matches one segment, and it has to stay that way.
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/conversations/{id}/read')]
	public function read(int $id): DataResponse {
		try {
			$this->initViewer(['write:conversations']);

			return new DataResponse(
				$this->conversationService->markRead($this->viewer, $id), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Removes the conversation from the list and answers `{}`, as Mastodon
	 * does. The messages themselves are not deleted — neither here nor there —
	 * and a later message in the same thread brings the conversation back.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/conversations/{id}')]
	public function delete(int $id): DataResponse {
		try {
			$this->initViewer(['write:conversations']);
			$this->conversationService->remove($this->viewer, $id);

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
	private function initViewer(array $scopes = ['read:statuses']): void {
		try {
			$userId = $this->currentSession($scopes);
			$this->viewer = $this->accountService->getActorFromUserId($userId, true);
		} catch (InsufficientScopeException $e) {
			throw $e;
		} catch (Exception $e) {
			// a missing, stale or made-up token is ordinary internet noise and
			// is answered with a 401, not logged as a fault
			$this->logger->debug('[ConversationController] no usable credentials', [
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
	 * contains it: `read:statuses` by `read:statuses` or by `read`, and by
	 * nothing else.
	 *
	 * Not by any other granular variant of the same parent — a token granted
	 * `read:lists` has not been granted the reader's private correspondence.
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
	 * A page with the `Link` header masto.js reads its cursor from — without
	 * it Elk and Phanpy show the first page and stop.
	 */
	private function paged(array $items, int $next, int $prev): DataResponse {
		$response = new DataResponse($items, Http::STATUS_OK);

		$links = [];
		if ($next > 0) {
			$links[] = '<' . $this->pageUrl(['max_id' => (string)$next]) . '>; rel="next"';
		}
		if ($prev > 0) {
			$links[] = '<' . $this->pageUrl(['min_id' => (string)$prev]) . '>; rel="prev"';
		}

		if ($links !== []) {
			$response->addHeader('Link', implode(', ', $links));
		}

		return $response;
	}

	/**
	 * This request's own URL with the cursor replaced, so every other filter
	 * the client sent survives into the next page.
	 */
	private function pageUrl(array $cursor): string {
		$uri = $this->request->getRequestUri();
		$path = $uri;
		$query = [];

		$pos = strpos($uri, '?');
		if ($pos !== false) {
			$path = substr($uri, 0, $pos);
			parse_str(substr($uri, $pos + 1), $query);
		}

		unset($query['max_id'], $query['min_id'], $query['since_id'], $query['_route']);

		return $path . '?' . http_build_query(array_merge($query, $cursor));
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

		// a conversation that is not there and a conversation that is somebody
		// else's are one answer, with Mastodon's own wording: telling them
		// apart would say whether a thread exists and who is in it
		if ($e instanceof ItemNotFoundException || $e instanceof CacheActorDoesNotExistException) {
			return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
		}

		$this->logger->error('[ConversationController] unexpected failure answering the client API', [
			'exception' => $e,
			'route' => (string)$this->request->getParam('_route', ''),
		]);

		return new DataResponse(
			['error' => 'internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}
}
