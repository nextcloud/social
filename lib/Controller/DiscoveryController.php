<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\DiscoveryRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\DirectoryService;
use OCA\Social\Service\FeaturedTagService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\SuggestionService;
use OCA\Social\Service\TrendService;
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
 * Finding things: the profile directory, who to follow, what is being read,
 * and the hashtags an account pins to its own profile.
 *
 * Four features in one controller because they are one thing to a client — a
 * Mastodon "explore" screen calls `/directory`, `/suggestions`, `/trends/*`
 * and `/featured_tags` to draw a single page — and because they share the one
 * rule that matters here: none of them may show an account or a post that the
 * caller would not have been shown anywhere else. A discovery surface is the
 * place where that goes wrong quietly, because nobody asked for the thing it
 * put in front of them.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]`, like `ApiController`,
 * `ListController` and `HistoryController`: a Mastodon client authenticates
 * with a bearer token and has no Nextcloud session or CSRF token to present.
 *
 * Which routes then require a viewer follows Mastodon exactly, and the split
 * is not arbitrary. The directory, the trends and another account's featured
 * tags are things this instance publishes about itself and are answered to
 * anybody. The suggestions and an account's own featured tags are about the
 * asking account and require a viewer — there is no such thing as an anonymous
 * "accounts you might follow".
 */
class DiscoveryController extends Controller {
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
		private DirectoryService $directoryService,
		private SuggestionService $suggestionService,
		private TrendService $trendService,
		private FeaturedTagService $featuredTagService,
		private LinkPreviewService $linkPreviewService,
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
	 * The local profile directory: the accounts that opted in to being listed.
	 *
	 * Opted in, and nothing else. `discoverable` has been stored on
	 * `social_actor` and federated on the actor since
	 * `Version1000Date20260911000002` and was read by nothing at all — a user
	 * could turn the flag off and it changed nothing, because there was no
	 * listing to be kept out of. It is honoured here as a predicate of the
	 * query, so an account that did not opt in is never a row that was read
	 * and then dropped.
	 *
	 * `local` is accepted and ignored, as documented: only accounts this
	 * instance holds the profile of have the flag, and publishing a directory
	 * of cached remote actors would be this instance listing somebody else's
	 * users.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/directory')]
	public function directory(
		int $offset = 0,
		int $limit = DirectoryService::LIMIT,
		string $order = DiscoveryRequest::ORDER_ACTIVE,
		bool $local = true,
	): DataResponse {
		try {
			$this->initViewer(['read'], false);

			return new DataResponse(
				$this->directoryService->page($order, $limit, $offset), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Accounts the viewer might want to follow, as Mastodon v2 `Suggestion`
	 * entities.
	 *
	 * Never somebody they already follow, have a pending request to, have
	 * blocked or muted, who has blocked them, or themselves — see
	 * SuggestionService, where those exclusions are gathered before either
	 * half of the list is built.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/suggestions')]
	public function suggestions(int $limit = SuggestionService::LIMIT): DataResponse {
		try {
			$this->initViewer(['read']);

			return new DataResponse(
				$this->suggestionService->suggestions($this->viewer->getId(), $limit),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The v1 shape of the same list: bare `Account` entities, without the
	 * source that explains them.
	 *
	 * Served from the same query rather than deprecated away, because clients
	 * that never moved to v2 would otherwise show an empty "who to follow"
	 * panel with no indication why.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/suggestions')]
	public function suggestionsV1(int $limit = SuggestionService::LIMIT): DataResponse {
		try {
			$this->initViewer(['read']);

			$accounts = [];
			foreach ($this->suggestionService->suggestions($this->viewer->getId(), $limit) as $suggestion) {
				$account = $suggestion->getAccount();
				$account->setExportFormat(ACore::FORMAT_LOCAL);
				$accounts[] = $account;
			}

			return new DataResponse($accounts, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The public statuses interacted with most in the window.
	 *
	 * `period` is this app's own parameter and is the one
	 * `/api/v1/trends/tags` already takes, so a client that asks all three
	 * trend routes reports on the same stretch of time. Mastodon has no such
	 * parameter and sends none; the default is what it gets.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/trends/statuses')]
	public function trendStatuses(
		int $limit = TrendService::LIMIT,
		int $offset = 0,
		string $period = HashtagService::PERIOD_DEFAULT,
	): DataResponse {
		try {
			$this->initViewer(['read'], false);

			$statuses = $this->trendService->trendingStatuses($period, $limit, $offset);
			// one query for the whole page, as the timelines do it
			$this->linkPreviewService->attachCards($statuses);

			return new DataResponse($statuses, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The links most often attached to a public status in the window. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/trends/links')]
	public function trendLinks(
		int $limit = TrendService::LIMIT,
		int $offset = 0,
		string $period = HashtagService::PERIOD_DEFAULT,
	): DataResponse {
		try {
			$this->initViewer(['read'], false);

			return new DataResponse(
				$this->trendService->trendingLinks($period, $limit, $offset), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The viewer's own featured tags. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/featured_tags')]
	public function featuredTags(): DataResponse {
		try {
			$this->initViewer(['read:accounts']);

			return new DataResponse(
				$this->featuredTagService->featured($this->viewer->getId()), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Pins a hashtag to the viewer's profile.
	 *
	 * A name that is not a hashtag is a 422 rather than a row nobody can post
	 * with, and so is one tag past the ceiling this instance advertises: a
	 * client that pre-checked against `max_featured_tags` and got it wrong
	 * must be told, not quietly refused.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/featured_tags')]
	public function featureTag(string $name = ''): DataResponse {
		try {
			$this->initViewer(['write:accounts']);

			return new DataResponse(
				$this->featuredTagService->feature($this->viewer->getId(), $name), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Answers `{}`, as Mastodon does. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/featured_tags/{id}', requirements: ['id' => '\\d+'])]
	public function unfeatureTag(int $id): DataResponse {
		try {
			$this->initViewer(['write:accounts']);
			$this->featuredTagService->unfeature($this->viewer->getId(), $id);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The hashtags the viewer posts with most and has not featured, as `Tag`
	 * entities.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	// Not reachable as a featured tag with the id "suggestions": the `{id}` of
	// unfeatureTag() is a `\d+`, and it has to stay one.
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/featured_tags/suggestions')]
	public function featuredTagSuggestions(): DataResponse {
		try {
			$this->initViewer(['read:accounts']);

			return new DataResponse(
				$this->featuredTagService->suggestions($this->viewer->getId()), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Somebody's featured tags.
	 *
	 * Public, as the profile it is drawn on is: a featured tag is a claim an
	 * account makes about itself, and the posts the counts are taken from are
	 * the public ones. A viewer is neither required nor used.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/{account}/featured_tags', requirements: ['account' => '.+'])]
	public function accountFeaturedTags(string $account): DataResponse {
		try {
			$this->initViewer(['read'], false);
			$actor = $this->resolveAccount($account);

			return new DataResponse(
				$this->featuredTagService->featured($actor->getId()), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The account behind what a client sent: Mastodon's numeric local id, an
	 * actor URI, or a handle. The same three forms `ApiController` and
	 * `ListController` accept wherever they take an account.
	 *
	 * @throws CacheActorDoesNotExistException
	 */
	private function resolveAccount(string $id): Person {
		$id = trim($id);

		if (is_numeric($id)) {
			if ((int)$id < 1) {
				throw new CacheActorDoesNotExistException('Record not found');
			}

			$actors = $this->cacheActorService->getFromNids([(int)$id]);
			if ($actors === []) {
				throw new CacheActorDoesNotExistException('Record not found');
			}

			return $actors[0];
		}

		if (str_starts_with($id, 'http://') || str_starts_with($id, 'https://')) {
			return $this->cacheActorService->getFromId($id);
		}

		if ($id === '') {
			throw new CacheActorDoesNotExistException('Record not found');
		}

		return $this->cacheActorService->getFromAccount(ltrim($id, '@'));
	}

	/**
	 * Resolves the viewer from the bearer token, or from the Nextcloud session
	 * when there is none — the same order `ApiController` and `ListController`
	 * use, because the same clients call all three.
	 *
	 * A bearer token presented on a route that does not require one still has
	 * its scope checked: a token granted less than it claims is refused rather
	 * than downgraded to the anonymous read the route would otherwise allow,
	 * which would turn a refusal into a partial success.
	 *
	 * @param string[] $scopes any one of which satisfies a bearer token
	 * @param bool $required whether a route may be answered with no viewer
	 *
	 * @throws ClientNotFoundException there is nobody to answer for
	 * @throws InsufficientScopeException the token is fine, its grant is not
	 */
	private function initViewer(array $scopes, bool $required = true): void {
		try {
			$userId = $this->currentSession($scopes);
			$this->viewer = $this->accountService->getActorFromUserId($userId, true);
			$this->cacheActorService->setViewer($this->viewer);
		} catch (InsufficientScopeException $e) {
			throw $e;
		} catch (Exception $e) {
			// a missing, stale or made-up token is ordinary internet noise and
			// is answered with a 401 or as an anonymous read, not logged as a
			// fault
			$this->logger->debug('[DiscoveryController] no usable credentials', [
				'exception' => $e->getMessage(),
			]);

			if ($required) {
				throw new ClientNotFoundException('the access_token was revoked');
			}
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
	 * contains it: `read:accounts` by `read:accounts` or by `read`, and by
	 * nothing else.
	 *
	 * Not by any other granular variant of the same parent — a token granted
	 * `read:statuses` has not been granted the reader's profile settings.
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
	 * these are `#[PublicPage]` routes, and echoing getMessage() publishes
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

		if ($e instanceof ItemNotFoundException || $e instanceof CacheActorDoesNotExistException) {
			return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
		}

		if ($e instanceof InvalidResourceException) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$this->logger->error('[DiscoveryController] unexpected failure answering the client API', [
			'exception' => $e,
			'route' => (string)$this->request->getParam('_route', ''),
		]);

		return new DataResponse(
			['error' => 'internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}
}
