<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConversationService;
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
class ConversationController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private ConversationService $conversationService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
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
			$this->initViewer(['read:statuses']);

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

}
