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
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AnnouncementService;
use OCA\Social\Service\ClientService;
use OCA\Social\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mastodon's announcements: the instance-wide notice an admin posts and every
 * account is shown once.
 *
 * Two surfaces, one subsystem, and they are not authenticated the same way.
 *
 * The two client routes are `#[PublicPage]` with `#[NoCSRFRequired]`, like
 * `ApiController` and `ListController`, and for the same reason: a Mastodon
 * client authenticates with a bearer token and has no Nextcloud session or
 * CSRF token to present, so `#[NoAdminRequired]` would refuse every real
 * caller before the handler ran. Both then require a viewer themselves — no
 * token, no session, 401 — so nothing is public in fact.
 *
 * The three `admin*` routes carry no attribute at all, which in Nextcloud
 * means an admin session and a CSRF token, as every route of
 * `ModerationController` does. They are the administration page's, never part
 * of the client API, and they are here rather than in that controller because
 * they read and write the same table as the routes above them: an admin
 * surface that drifted from the client entity is how an announcement gets
 * posted that nobody is served.
 */
class AnnouncementController extends Controller {
	private string $bearer = '';
	private ?SocialClient $client = null;
	private ?Person $viewer = null;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private AccountService $accountService,
		private ClientService $clientService,
		private AnnouncementService $announcementService,
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
	 * The announcements that apply now, each carrying whether the viewer has
	 * read it.
	 *
	 * Unpaged, as Mastodon's is, and a dismissed announcement is still in it:
	 * `read` is what changed, not what is served. Mastodon asks for no
	 * particular scope here — any user token may read what the instance is
	 * telling everybody — and neither does this.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/announcements')]
	public function index(): DataResponse {
		try {
			$this->initViewer();

			return new DataResponse(
				$this->announcementService->active($this->viewer->getId()), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Marks one announcement read for the viewer, and answers `{}` as Mastodon
	 * does. `write:accounts` is the scope Mastodon documents for it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/announcements/{id}/dismiss', requirements: ['id' => '\\d+'])]
	public function dismiss(int $id): DataResponse {
		try {
			$this->initViewer(['write:accounts']);
			$this->announcementService->dismiss($id, $this->viewer->getId());

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Puts an emoji on an announcement, for the viewer, and answers `{}` as
	 * Mastodon does.
	 *
	 * The only thing an account can say back about an instance-wide notice.
	 * Until this route existed, the only thing anybody could do with one was
	 * put it away, and `Announcement.reactions` was always `[]`.
	 *
	 * The emoji is in the path, as it is on Mastodon, so it arrives
	 * percent-encoded and the router has already decoded it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function react(int $id, string $name): DataResponse {
		try {
			$this->initViewer(['write:favourites']);
			$this->announcementService->react($id, $this->viewer->getId(), $name);

			return new DataResponse([], Http::STATUS_OK);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Takes one back. Taking back one that was never there answers `{}` too:
	 * a client that has lost track of what it sent should not be told the
	 * announcement does not exist.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function unreact(int $id, string $name): DataResponse {
		try {
			$this->initViewer(['write:favourites']);
			$this->announcementService->unreact($id, $this->viewer->getId(), $name);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Every announcement there is, for the administration page. */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'GET', url: '/admin/announcements')]
	public function adminIndex(): DataResponse {
		return new DataResponse(['announcements' => $this->announcementService->adminList()]);
	}

	/**
	 * Posts one, and answers with the whole list so the page redraws from what
	 * is stored rather than from what it hoped was stored.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/admin/announcements')]
	public function adminCreate(
		string $text = '',
		string $starts_at = '',
		string $ends_at = '',
		bool $all_day = false,
	): DataResponse {
		try {
			$this->announcementService->create($text, $starts_at, $ends_at, $all_day);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(['announcements' => $this->announcementService->adminList()]);
	}

	/** Removes one, with every dismissal of it, and answers with the rest. */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'DELETE', url: '/admin/announcements/{id}', requirements: ['id' => '\\d+'])]
	public function adminDelete(int $id): DataResponse {
		try {
			$this->announcementService->delete($id);
		} catch (ItemNotFoundException $e) {
			return new DataResponse(['error' => 'announcement not found'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['announcements' => $this->announcementService->adminList()]);
	}

	/**
	 * Resolves the viewer from the bearer token, or from the Nextcloud session
	 * when there is none — the same order `ApiController` uses, because the
	 * same clients call both.
	 *
	 * @param string[] $scopes any one of which satisfies a bearer token; none
	 *                         means any token will do, which is what Mastodon
	 *                         requires of the read
	 *
	 * @throws ClientNotFoundException there is nobody to answer for
	 * @throws InsufficientScopeException the token is fine, its grant is not
	 */
	private function initViewer(array $scopes = []): void {
		try {
			$userId = $this->currentSession($scopes);
			$this->viewer = $this->accountService->getActorFromUserId($userId, true);
		} catch (InsufficientScopeException $e) {
			throw $e;
		} catch (Exception $e) {
			// a missing, stale or made-up token is ordinary internet noise and
			// is answered with a 401, not logged as a fault
			$this->logger->debug('[AnnouncementController] no usable credentials', [
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
	 * contains it: `write:accounts` by `write:accounts` or by `write`, and by
	 * nothing else — not by any other granular variant of the same parent.
	 *
	 * An empty list is not "no check passed" but "no scope is required", which
	 * is how Mastodon documents the read.
	 *
	 * @param string[] $accepted
	 *
	 * @throws InsufficientScopeException
	 */
	private function checkTokenScope(array $accepted): void {
		if ($accepted === []) {
			return;
		}

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

		if ($e instanceof ItemNotFoundException) {
			return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
		}

		if ($e instanceof InvalidResourceException) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$this->logger->error('[AnnouncementController] unexpected failure answering the client API', [
			'exception' => $e,
			'route' => (string)$this->request->getParam('_route', ''),
		]);

		return new DataResponse(
			['error' => 'internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}
}
