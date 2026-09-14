<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\NotificationService;
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
class NotificationController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private NotificationService $notificationService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
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

}
