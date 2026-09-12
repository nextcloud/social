<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PixelfedConfigService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Service\SuggestionService;
use OCA\Social\Service\TrendService;
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
 * The routes the Pixelfed app asks for that live nowhere else.
 *
 * Pixelfed speaks the Mastodon client API for almost everything, and everything
 * it shares is served by `ApiController` and the rest. What is left is a small
 * surface of its own: a config object it reads on launch, and a `v1.1` namespace
 * its discover screen uses. They are gathered here rather than scattered,
 * because they have one thing in common that the rest of this API does not --
 * they exist to satisfy one client, and a reader should be able to see the whole
 * of that in one file.
 *
 * **This is not all of Pixelfed's `v1.1`.** It is the part its official app
 * actually calls to start up and to draw discover. Routes are added when
 * something asks for them, not to fill in a namespace.
 *
 * Nothing here ranks or selects anything of its own. Discover is the same
 * `TrendService` the Mastodon trend routes use and the same `SuggestionService`
 * behind `/api/v2/suggestions` -- so a post or an account cannot be popular on
 * one route and absent from the other, which is the kind of disagreement nobody
 * finds until they are looking at two screens side by side.
 */
class PixelfedController extends ClientApiController {
	/** What one page of discover holds. */
	private const LIMIT = 20;

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private PixelfedConfigService $pixelfedConfigService,
		private TrendService $trendService,
		private SuggestionService $suggestionService,
		private HashtagService $hashtagService,
		private LinkPreviewService $linkPreviewService,
		private PlaceService $placeService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/**
	 * The numbers and switches the app reads once, on launch.
	 *
	 * Public, and answered without a viewer: the app asks for it before anybody
	 * has signed in, to decide whether it can talk to this server at all.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/config')]
	public function config(): DataResponse {
		try {
			return new DataResponse($this->pixelfedConfigService->config(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Accounts worth following: `/api/v1.1/discover/accounts/popular`.
	 *
	 * The same suggestions `/api/v2/suggestions` answers, unwrapped -- Mastodon
	 * wraps each account in a `{source, account}` suggestion and Pixelfed sends
	 * the accounts themselves. One list, two shapes, rather than two lists that
	 * can disagree.
	 *
	 * Needs a viewer, as the Mastodon route does: there is no such thing as an
	 * anonymous "accounts you might follow".
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/discover/accounts/popular')]
	public function popularAccounts(int $limit = self::LIMIT): DataResponse {
		try {
			$this->initViewer(['read']);

			$accounts = [];
			foreach (
				$this->suggestionService->suggestions(
					$this->viewer()->getId(), max(1, min($limit, self::LIMIT))
				) as $suggestion
			) {
				// Mastodon wraps each account in a {source, account}
				// suggestion; Pixelfed sends the accounts themselves
				$accounts[] = $suggestion->getAccount();
			}

			return new DataResponse($accounts, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The pictures being looked at: `/api/v1.1/discover/posts`.
	 *
	 * The same answer as `/api/v2/discover/posts`, at the path the app asks for.
	 * Deliberately a second route onto one implementation rather than a second
	 * implementation: a post cannot then trend on one and not the other.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/discover/posts')]
	public function discoverPosts(
		int $limit = self::LIMIT,
		int $offset = 0,
		string $period = HashtagService::PERIOD_DEFAULT,
	): DataResponse {
		try {
			$this->optionalViewer(['read']);

			$statuses = $this->trendService->trendingStatuses(
				$period, max(1, min($limit, self::LIMIT)), max(0, $offset), true
			);
			foreach ($statuses as $status) {
				$status->setExportFormat(ACore::FORMAT_LOCAL);
			}
			$this->linkPreviewService->attachCards($statuses);
			$this->placeService->attachPlaces($statuses);

			return new DataResponse($statuses, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The hashtags being used: `/api/v1.1/discover/posts/hashtags`.
	 *
	 * Pixelfed's discover screen draws a row of tags above the grid. The same
	 * trending tags `/api/v1/trends/tags` answers.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/discover/posts/hashtags')]
	public function discoverHashtags(
		int $limit = self::LIMIT,
		string $period = HashtagService::PERIOD_DEFAULT,
	): DataResponse {
		try {
			$this->optionalViewer(['read']);

			return new DataResponse(
				$this->hashtagService->getTrending(max(1, min($limit, self::LIMIT)), $period),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
