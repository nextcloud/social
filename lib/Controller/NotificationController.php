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
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\NotificationPolicy;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\NotificationGroupService;
use OCA\Social\Service\NotificationPolicyService;
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
		private NotificationGroupService $notificationGroupService,
		private NotificationPolicyService $notificationPolicyService,
		private FilterService $filterService,
		private CacheActorService $cacheActorService,
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
				// gone between the read and the dismiss; the state asked for
				// is the state there is
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

	// Mastodon 4.3: the same notifications, grouped

	/**
	 * `GET /api/v2/notifications`: the viewer's notifications, grouped.
	 *
	 * The shape is Mastodon's `GroupedNotificationsResults` — groups, plus the
	 * accounts and statuses they refer to, each carried once. A page of forty
	 * favourites of one post is one group and one status here, where v1 sends
	 * forty rows and forty copies of the post.
	 *
	 * Declared before the `{group_key}` routes below, because within a
	 * controller the order the methods are written in is the order the routes
	 * are tried in and `unread_count` would otherwise be read as a group key.
	 *
	 * @param string[] $types
	 * @param string[] $exclude_types
	 * @param string[] $grouped_types
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/notifications/unread_count')]
	public function unreadCountV2(): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			// counted over groups, not rows: the number a client puts on the
			// bell should say how many things happened, and one popular post
			// is one thing
			$page = $this->visible(ProbeOptions::MAX_LIMIT);
			$grouped = $this->notificationGroupService->group($page);

			return new DataResponse(
				['count' => count($grouped['notification_groups'])], Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * @param string[] $types
	 * @param string[] $exclude_types
	 * @param string[] $grouped_types
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/notifications')]
	public function indexV2(
		int $limit = 40,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since_id = 0,
		array $types = [],
		array $exclude_types = [],
		array $grouped_types = [],
		string $account_id = '',
	): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			$page = $this->visible($limit, $max_id, $min_id, $since_id, $types, $exclude_types, $account_id);

			return new DataResponse(
				$this->notificationGroupService->group($page, $grouped_types), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** One group, in the same shape the list serves it in. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/notifications/{group_key}')]
	public function group(string $group_key): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			$members = $this->notificationGroupService->membersOf(
				$this->visible(NotificationPolicyService::LOOKBACK), $group_key
			);
			if ($members === []) {
				throw new ItemNotFoundException('Record not found');
			}

			return new DataResponse($this->notificationGroupService->group($members), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Everybody in one group, not only the sample the group carries.
	 *
	 * This is what "and 34 others" opens, so it is the whole set rather than
	 * the eight accounts the group lists.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/notifications/{group_key}/accounts')]
	public function groupAccounts(string $group_key): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			$accounts = [];
			foreach ($this->notificationGroupService->membersOf(
				$this->visible(NotificationPolicyService::LOOKBACK), $group_key
			) as $notification) {
				if (!$notification->hasActor()) {
					continue;
				}

				$actor = $notification->getActor();
				$actor->setExportFormat(ACore::FORMAT_LOCAL);
				$accounts[$actor->getId()] = $actor;
			}

			return new DataResponse(array_values($accounts), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Dismisses every notification in one group.
	 *
	 * A group is what the reader sees, so dismissing it has to mean the rows
	 * behind it: dismissing only the most recent would leave the group on
	 * screen with one fewer in it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v2/notifications/{group_key}/dismiss')]
	public function groupDismiss(string $group_key): DataResponse {
		try {
			$this->initViewer(['write:notifications']);

			foreach ($this->notificationGroupService->membersOf(
				$this->visible(NotificationPolicyService::LOOKBACK), $group_key
			) as $notification) {
				try {
					$this->notificationService->dismiss($this->viewer, $notification->getNid());
				} catch (ItemNotFoundException $e) {
					// gone between the read and the dismiss; the state asked
					// for is the state there is
				}
			}

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	// Mastodon 4.3: the policy, and the inbox it fills

	/** What this account does with notifications from people it has no relationship with. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/notifications/policy')]
	// Mastodon moved the policy to v2 in 4.3 and a 4.3 client looks there
	// only; the v1 spelling stays for the clients written against 4.2, which
	// is the release this server used to announce.
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/notifications/policy', postfix: 'v2')]
	public function policy(): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			return new DataResponse($this->policyWithSummary(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Changes the policy. What is not named is left as it is, so a client that
	 * turns one of the five on does not reset the other four.
	 *
	 * `PATCH`, which is what Mastodon 4.3 uses. A decision this does not
	 * recognise leaves its key alone rather than failing the request: a client
	 * from a newer Mastodon sending a sixth key must not lose the five that
	 * work here.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v1/notifications/policy')]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v2/notifications/policy', postfix: 'v2')]
	public function policyUpdate(): DataResponse {
		try {
			$this->initViewer(['write:notifications']);

			$this->notificationPolicyService->save($this->userId(), $this->body());

			return new DataResponse($this->policyWithSummary(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Whether the held notifications have been merged back into the list.
	 *
	 * Mastodon answers `false` while it is still moving rows about after a
	 * policy change. Nothing is moved here — the policy is applied when the
	 * list is read — so there is never anything in flight, and the answer is
	 * always `true`.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/notifications/requests/merged')]
	public function requestsMerged(): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			return new DataResponse(['merged' => true], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Accepts several senders at once: `id[]`, as Mastodon sends it. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/notifications/requests/accept')]
	public function requestsAccept(array $id = []): DataResponse {
		return $this->decideMany($id, true);
	}

	/** Dismisses several senders at once. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/notifications/requests/dismiss')]
	public function requestsDismiss(array $id = []): DataResponse {
		return $this->decideMany($id, false);
	}

	/**
	 * The senders whose notifications the policy is holding, one row each with
	 * how many they have sent.
	 *
	 * One row per sender is the point: somebody held back has usually sent
	 * more than one thing, and being asked about each in turn is what makes a
	 * filtered inbox worse than an unfiltered one.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/notifications/requests')]
	public function requests(int $limit = NotificationPolicyService::REQUESTS_LIMIT): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			$limit = max(1, min(NotificationPolicyService::REQUESTS_MAX_LIMIT, $limit));

			return new DataResponse(array_slice($this->heldRequests(), 0, $limit), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** One of those rows. The id is the sender's account id. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/notifications/requests/{id}', requirements: ['id' => '\\d+'])]
	public function request(int $id): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			foreach ($this->heldRequests() as $request) {
				if ($request->getAccount()->getNid() === (string)$id) {
					return new DataResponse($request, Http::STATUS_OK);
				}
			}

			throw new ItemNotFoundException('Record not found');
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * "Show me this account's notifications after all."
	 *
	 * The decision is about the account, so it settles what they have already
	 * sent and what they send later — which is why the request inbox is worth
	 * having at all.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/notifications/requests/{id}/accept', requirements: ['id' => '\\d+'])]
	public function requestAccept(int $id): DataResponse {
		return $this->decideMany([(string)$id], true);
	}

	/**
	 * "Stop asking me about this account."
	 *
	 * What they have sent stays where it is and stays hidden; what changes is
	 * that they are no longer offered as a decision to take. Mastodon deletes
	 * the notifications; nothing is deleted here, so a policy the reader
	 * loosens later still has something to show.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/notifications/requests/{id}/dismiss', requirements: ['id' => '\\d+'])]
	public function requestDismiss(int $id): DataResponse {
		return $this->decideMany([(string)$id], false);
	}

	/**
	 * The page of notifications this viewer is meant to see: what the query
	 * returned, less what a keyword filter hides and what the policy holds.
	 *
	 * @param string[] $types
	 * @param string[] $excludeTypes
	 *
	 * @return \OCA\Social\Model\ActivityPub\Stream[]
	 */
	private function visible(
		int $limit,
		int|string $maxId = '0',
		int|string $minId = 0,
		int|string $sinceId = '0',
		array $types = [],
		array $excludeTypes = [],
		string $accountId = '',
	): array {
		$page = $this->notificationService->timeline(
			$this->viewer, $limit, $maxId, $minId, $sinceId, $types, $excludeTypes, $accountId
		);

		return $this->filterService->applyToNotifications(
			$this->notificationPolicyService->partition($this->viewer, $page)['shown'],
			$this->viewer
		);
	}

	/**
	 * The requests inbox, built from the notifications the policy is holding.
	 *
	 * @return \OCA\Social\Model\Client\NotificationRequest[]
	 */
	private function heldRequests(): array {
		$page = $this->notificationService->timeline(
			$this->viewer, NotificationPolicyService::LOOKBACK
		);
		$held = $this->notificationPolicyService->partition($this->viewer, $page)['held'];

		$senders = [];
		foreach ($held as $notification) {
			if ($notification->hasActor()) {
				$senders[] = $notification->getActor()->getId();
			}
		}

		$decided = $this->notificationPolicyService->decisionsAbout(
			$this->viewer->getId(), array_values(array_unique($senders))
		);

		return $this->notificationPolicyService->requestsFrom($held, $decided['dismissed']);
	}

	/** The policy with the counts a client draws the badge from. */
	private function policyWithSummary(): NotificationPolicy {
		$requests = $this->heldRequests();

		$notifications = 0;
		foreach ($requests as $request) {
			$notifications += $request->getCount();
		}

		return $this->notificationPolicyService->of($this->userId())
			->setSummary(count($requests), $notifications);
	}

	/**
	 * Accepts or dismisses the senders named by account id, and answers `{}`.
	 *
	 * An id that names no account is skipped rather than refused: these
	 * arrive as a list from a client clearing a screenful, and one stale entry
	 * must not lose the rest of the decisions.
	 *
	 * @param array<mixed> $ids
	 */
	private function decideMany(array $ids, bool $accept): DataResponse {
		try {
			$this->initViewer(['write:notifications']);

			foreach ($ids as $id) {
				if ((!is_string($id) && !is_int($id)) || !ctype_digit((string)$id) || \OCA\Social\Tools\Nid::compare($id, '0') < 1) {
					continue;
				}
				$nid = \OCA\Social\Tools\Nid::fromStorage($id);

				$accounts = $this->cacheActorService->getFromNids([$nid]);
				if ($accounts === []) {
					continue;
				}

				if ($accept) {
					$this->notificationPolicyService->accept($this->viewer, $accounts[0]);
				} else {
					$this->notificationPolicyService->dismiss($this->viewer, $accounts[0]);
				}
			}

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The Nextcloud user behind the viewer, which is where the policy is stored. */
	private function userId(): string {
		return $this->viewer->getUserId();
	}

	/**
	 * The request body, whether it arrived as JSON or as a form.
	 *
	 * @return array<string, mixed>
	 */
	private function body(): array {
		return $this->request->getParams();
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
			// read-only, for the reason in ApiController::initViewer()
			$this->viewer = $this->accountService->getActorFromUserId($userId);
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
