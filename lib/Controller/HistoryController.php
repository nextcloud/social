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
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\StatusRevisionService;
use OCA\Social\Service\StreamService;
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
 * What a status used to say: `GET /api/v1/statuses/:id/history`.
 *
 * A route of its own rather than another method on `ApiController` because
 * the revisions are a subsystem of their own — their own table, their own
 * request class and their own entity — and because the visibility rule it
 * needs is the one `ApiController::statusGet()` already applies and nothing
 * more.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]`, like `ApiController` and
 * `ListController`: a Mastodon client authenticates with a bearer token and
 * has no Nextcloud session or CSRF token to present.
 *
 * Unlike the list routes, a viewer is *optional* here — Mastodon serves the
 * history of a public status to anybody, and so does `statusGet` for the
 * status itself, so requiring one would hide the history of a post whose
 * current text is public. Nothing is thereby exposed: the status is resolved
 * through `StreamService::getStreamByNid()`, whose visibility filter is a
 * predicate of the statement, so a status the caller may not read is a 404
 * before any revision is looked at. A bearer token that *is* presented still
 * has its scope checked — a token granted less than it claims does not become
 * an anonymous caller, it is refused.
 */
class HistoryController extends Controller {
	private string $bearer = '';
	private ?SocialClient $client = null;
	private ?Person $viewer = null;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private AccountService $accountService,
		private CacheActorService $cacheActorService,
		private ClientService $clientService,
		private StreamService $streamService,
		private StatusRevisionService $revisionService,
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
	 * Every version of the status, oldest first, as Mastodon `StatusEdit`
	 * entities — the first one being what was posted and the last what is
	 * showing now.
	 *
	 * Unpaged, as Mastodon's is: a status has as many versions as its author
	 * made by hand, and a client draws the whole "edited" dialog from one
	 * call.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/history')]
	public function history(int $nid): DataResponse {
		try {
			$this->initViewer();

			$stream = $this->streamService->getStreamByNid($nid);

			return new DataResponse($this->revisionService->history($stream), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Resolves the viewer from the bearer token, or from the Nextcloud session
	 * when there is none — the same order `ApiController` and `ListController`
	 * use, because the same clients call all three.
	 *
	 * A caller with no credentials at all is left anonymous rather than
	 * refused, and the stream layer then answers them as the public internet.
	 * A caller whose token exists but does not carry the scope is refused: the
	 * failure is re-thrown out of here so that it becomes a 403 naming the
	 * missing scope, and not a silent downgrade to an anonymous read.
	 *
	 * @param string[] $scopes any one of which satisfies a bearer token
	 *
	 * @throws InsufficientScopeException the token is fine, its grant is not
	 */
	private function initViewer(array $scopes = ['read:statuses']): void {
		try {
			$userId = $this->currentSession($scopes);
			$actor = $this->accountService->getActorFromUserId($userId, true);
			$this->viewer = $this->cacheActorService->getFromLocalAccount(
				$actor->getPreferredUsername()
			);
			$this->viewer->setExportFormat(ACore::FORMAT_LOCAL);

			$this->streamService->setViewer($this->viewer);
			$this->cacheActorService->setViewer($this->viewer);
		} catch (InsufficientScopeException $e) {
			throw $e;
		} catch (Exception $e) {
			// a missing, stale or made-up token is ordinary internet noise and
			// is answered as an anonymous read, not logged as a fault
			$this->logger->debug('[HistoryController] no usable credentials', [
				'exception' => $e->getMessage(),
			]);
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
	 * nothing else — not by any other granular variant of the same parent.
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
	 * A failure as a Mastodon client can act on it. An unrecognised failure is
	 * a bug on this side, so it answers 500 and its message is not sent on —
	 * this is a `#[PublicPage]` route, and echoing getMessage() publishes
	 * whatever the failure happened to name.
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

		// a status that is not there and a status the caller may not read are
		// one answer, as they are on Mastodon: telling them apart would say
		// whether a followers-only post exists
		if ($e instanceof StreamNotFoundException || $e instanceof ItemNotFoundException) {
			return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
		}

		$this->logger->error('[HistoryController] unexpected failure answering the client API', [
			'exception' => $e,
			'route' => (string)$this->request->getParam('_route', ''),
		]);

		return new DataResponse(
			['error' => 'internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}
}
