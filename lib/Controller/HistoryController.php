<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\StatusRevisionService;
use OCA\Social\Service\StreamService;
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
class HistoryController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		private CacheActorService $cacheActorService,
		ClientService $clientService,
		private StreamService $streamService,
		private StatusRevisionService $revisionService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
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
			$this->initViewer(['read:statuses']);

			$stream = $this->streamService->getStreamByNid($nid);

			return new DataResponse($this->revisionService->history($stream), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * A caller with no credentials at all is left anonymous rather than
	 * refused, and the stream layer then answers them as the public internet:
	 * Mastodon serves the history of a public status to anybody. A caller
	 * whose token exists but does not carry the scope is still refused -- the
	 * base class raises that ahead of everything else, and it is not caught
	 * here, so it becomes a 403 naming the missing scope rather than a silent
	 * downgrade to an anonymous read.
	 */
	#[\Override]
	protected function initViewer(array $scopes): void {
		try {
			parent::initViewer($scopes);
			$viewer = $this->cacheActorService->getFromLocalAccount(
				$this->viewer()->getPreferredUsername()
			);
		} catch (InsufficientScopeException $e) {
			throw $e;
		} catch (ClientNotFoundException $e) {
			return;
		} catch (Exception $e) {
			$this->logger->debug('[HistoryController] viewer is not in the actor cache', [
				'exception' => $e->getMessage(),
			]);
			$this->viewer = null;

			return;
		}

		$viewer->setExportFormat(ACore::FORMAT_LOCAL);
		$this->viewer = $viewer;
		$this->streamService->setViewer($viewer);
		$this->cacheActorService->setViewer($viewer);
	}

	#[\Override]
	protected function error(Throwable $e): DataResponse {
		// a status that is not there and a status the caller may not read are
		// one answer, as they are on Mastodon: telling them apart would say
		// whether a followers-only post exists
		if ($e instanceof StreamNotFoundException) {
			return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
		}

		return parent::error($e);
	}
}
