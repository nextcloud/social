<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Db\DiscoveryRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\DirectoryService;
use OCA\Social\Service\FeaturedTagService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Service\StarterPackService;
use OCA\Social\Service\SuggestionService;
use OCA\Social\Service\TrendService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
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
class DiscoveryController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		private CacheActorService $cacheActorService,
		ClientService $clientService,
		private DirectoryService $directoryService,
		private SuggestionService $suggestionService,
		private TrendService $trendService,
		private FeaturedTagService $featuredTagService,
		private LinkPreviewService $linkPreviewService,
		private PlaceService $placeService,
		private StarterPackService $starterPackService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
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
			$this->optionalViewer(['read']);

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
			$this->optionalViewer(['read']);

			$statuses = $this->trendService->trendingStatuses($period, $limit, $offset);
			// one query for the whole page, as the timelines do it
			$this->linkPreviewService->attachCards($statuses);
			$this->placeService->attachPlaces($statuses);

			return new DataResponse($statuses, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The pictures being looked at right now: `/api/v2/discover/posts`.
	 *
	 * Pixelfed's route, and the one its clients ask for the discover screen. It
	 * is the trending statuses narrowed to the ones with a picture, because a
	 * discover screen is a grid of squares and a text post is a poor thing to
	 * put in one -- not a different ranking, so a post cannot trend here and not
	 * there.
	 *
	 * `media` narrows it again to one kind, which is this app's own parameter
	 * and not Pixelfed's: its clients send none and get what they always got,
	 * every post with an attachment. The page this app draws asks for `image`
	 * and `video` separately, because a grid of squares and a grid of players
	 * are two screens. Anything else is ignored rather than refused -- an
	 * unknown kind is a client asking for something this instance does not
	 * sort by, not an error worth a 4xx on a shop window.
	 *
	 * Public statuses only, as the trends are: this is a shop window, and the
	 * one rule a discovery surface must not break is showing somebody something
	 * they would not have been shown anywhere else.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/discover/posts')]
	public function discoverPosts(
		int $limit = TrendService::LIMIT,
		int $offset = 0,
		string $period = HashtagService::PERIOD_DEFAULT,
		string $media = '',
	): DataResponse {
		try {
			$this->optionalViewer(['read']);

			$media = in_array($media, ['image', 'video', 'audio'], true) ? $media : '';
			$statuses = $this->trendService->trendingStatuses($period, $limit, $offset, true, $media);
			$this->linkPreviewService->attachCards($statuses);
			$this->placeService->attachPlaces($statuses);

			return new DataResponse($statuses, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The starter packs: named handfuls of accounts worth following.
	 *
	 * The index resolves nobody. A handle becomes a profile through a WebFinger
	 * lookup and an actor fetch against somebody else's server, and doing that
	 * for every handle of every pack in order to draw a list of pack *names*
	 * would make this page wait on the internet for nothing.
	 *
	 * Public: what this instance suggests is something it publishes about
	 * itself, and a signed-out visitor deciding whether to join deserves to see
	 * it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/starter_packs')]
	public function starterPacks(): DataResponse {
		try {
			return new DataResponse($this->starterPackService->packs(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * One pack, with its handles resolved to profiles.
	 *
	 * This one does reach other servers, which is why it is a route of its own
	 * rather than a fatter index: the cost is paid when somebody opens a pack,
	 * not when they glance at the page.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/starter_packs/{slug}', requirements: ['slug' => '[a-z0-9-]+'])]
	public function starterPack(string $slug): DataResponse {
		try {
			return new DataResponse($this->starterPackService->pack($slug), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Follows everyone in a pack that can be reached.
	 *
	 * The whole point of the button is that nobody has to follow six accounts by
	 * hand, so one unreachable host skips that account rather than failing the
	 * lot. The answer says who was actually followed.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[UserRateLimit(limit: 10, period: 60)]
	#[FrontpageRoute(
		verb: 'POST',
		url: '/api/v1/starter_packs/{slug}/follow',
		requirements: ['slug' => '[a-z0-9-]+']
	)]
	public function followStarterPack(string $slug): DataResponse {
		try {
			$this->initViewer(['write:follows']);

			return new DataResponse(
				['followed' => $this->starterPackService->followAll($this->viewer, $slug)],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The links most often attached to a public status in the window. */
	/**
	 * The public posts carrying one link, newest first.
	 *
	 * What a reader gets by tapping a trending link rather than following it
	 * off the instance. The links themselves were already served at
	 * `/api/v1/trends/links`, so the data was here and the timeline that reads
	 * it was not.
	 *
	 * A missing or unknown `url` is an empty timeline, not an error: the link
	 * a client holds may be one nobody here has posted since.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/timelines/link')]
	public function linkTimeline(
		string $url = '',
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
	): DataResponse {
		try {
			$this->optionalViewer(['read']);

			$statuses = $this->trendService->linkTimeline($url, $limit, $max_id, $min_id);
			// one query for the whole page, as the timelines do it
			$this->linkPreviewService->attachCards($statuses);

			return new DataResponse($statuses, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/trends/links')]
	public function trendLinks(
		int $limit = TrendService::LIMIT,
		int $offset = 0,
		string $period = HashtagService::PERIOD_DEFAULT,
	): DataResponse {
		try {
			$this->optionalViewer(['read']);

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
			$this->optionalViewer(['read']);
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
	 * The viewer, and the actor cache told who is reading, so that what it
	 * hands back about other accounts is what this reader may see.
	 */
	#[\Override]
	protected function initViewer(array $scopes): void {
		parent::initViewer($scopes);
		$this->cacheActorService->setViewer($this->viewer());
	}
}
