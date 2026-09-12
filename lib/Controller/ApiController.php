<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AP;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\HashtagDoesNotExistException;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidHandleException;
use OCA\Social\Exceptions\InvalidResourceEntryException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\TooManyRequestsException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Exceptions\UnknownProbeException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\Post;
use OCA\Social\Model\Report;
use OCA\Social\Response\StreamedRemoteResponse;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActionService;
use OCA\Social\Service\AvatarService;
use OCA\Social\Service\BannerService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\EmojiService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\MarkerService;
use OCA\Social\Service\PinService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\RelationshipService;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\ScheduledStatusService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Traits\TNCDataResponse;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Class ApiController
 *
 * @package OCA\Social\Controller
 */
class ApiController extends Controller {
	use TNCDataResponse;

	private IURLGenerator $urlGenerator;
	private IUserSession $userSession;
	private LoggerInterface $logger;
	private InstanceService $instanceService;
	private ClientService $clientService;
	private AccountService $accountService;
	private CacheActorService $cacheActorService;
	private CacheDocumentService $cacheDocumentService;
	private DocumentService $documentService;
	private FollowService $followService;
	private RelationshipService $relationshipService;
	private StreamService $streamService;
	private ActionService $actionService;
	private PostService $postService;
	private PollService $pollService;
	private ReportService $reportService;
	private SearchService $searchService;
	private ConfigService $configService;
	private CurlService $curlService;

	/** where a used Idempotency-Key is remembered, and for how long */
	private const IDEMPOTENCY_CACHE = 'social_idempotency';

	/** Accounts one `familiar_followers` call answers for; Mastodon's cap too. */
	private const FAMILIAR_FOLLOWERS_MAX = 20;
	private const IDEMPOTENCY_TTL = 3600;

	/**
	 * How long `/media/{uuid}` may be cached: a year, the conventional
	 * "forever" for content whose URL names its bytes and never changes.
	 */
	private const MEDIA_CACHE_SECONDS = 31536000;

	private string $bearer = '';
	private ?SocialClient $client = null;
	private ?Person $viewer = null;

	public function __construct(
		IRequest $request,
		IURLGenerator $urlGenerator,
		IUserSession $userSession,
		LoggerInterface $logger,
		InstanceService $instanceService,
		ClientService $clientService,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		CacheDocumentService $cacheDocumentService,
		DocumentService $documentService,
		FollowService $followService,
		RelationshipService $relationshipService,
		StreamService $streamService,
		ActionService $actionService,
		PostService $postService,
		PollService $pollService,
		private PinService $pinService,
		private HashtagService $hashtagService,
		private MarkerService $markerService,
		private StreamRequest $streamRequest,
		ReportService $reportService,
		SearchService $searchService,
		ConfigService $configService,
		CurlService $curlService,
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private ICacheFactory $cacheFactory,
		private IRootFolder $rootFolder,
		private ITempManager $tempManager,
		private FilterService $filterService,
		private BannerService $bannerService,
		private AvatarService $avatarService,
		private AccountRelationService $accountRelationService,
		private ScheduledStatusService $scheduledStatusService,
		private EmojiService $emojiService,
		private IAppManager $appManager,
		private FediverseService $fediverseService,
		private PlaceService $placeService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->urlGenerator = $urlGenerator;
		$this->userSession = $userSession;
		$this->logger = $logger;
		$this->instanceService = $instanceService;
		$this->clientService = $clientService;
		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->cacheDocumentService = $cacheDocumentService;
		$this->documentService = $documentService;
		$this->followService = $followService;
		$this->relationshipService = $relationshipService;
		$this->streamService = $streamService;
		$this->actionService = $actionService;
		$this->postService = $postService;
		$this->pollService = $pollService;
		$this->reportService = $reportService;
		$this->searchService = $searchService;
		$this->configService = $configService;
		$this->curlService = $curlService;

		$authHeader = trim($this->request->getHeader('Authorization'));
		if (strpos($authHeader, ' ')) {
			[$authType, $authToken] = explode(' ', $authHeader);
			if (strtolower($authType) === 'bearer') {
				$this->bearer = $authToken;
			}
		}
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/apps/verify_credentials')]
	public function appsCredentials() {
		try {
			$this->initViewer(true);

			// `vapid_key` is always present, because a client reads it out of
			// this response before it decides whether to offer push at all; it
			// is empty because this app has no Web Push endpoint, which is the
			// answer that makes a client stop asking.
			if ($this->client === null) {
				return new DataResponse(
					[
						'name' => 'Nextcloud Social',
						'website' => 'https://github.com/nextcloud/social/',
						'vapid_key' => ''
					], Http::STATUS_OK
				);
			} else {
				return new DataResponse(
					[
						'name' => $this->client->getAppName(),
						'website' => $this->client->getAppWebsite(),
						'vapid_key' => ''
					], Http::STATUS_OK
				);
			}
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/verify_credentials')]
	public function verifyCredentials() {
		try {
			$this->initViewer(true);

			return new DataResponse($this->accountEntity($this->viewer), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's profile update: `display_name`, `note` (the bio), `avatar` and
	 * `header` (multipart), `locked` (manually approve followers),
	 * `discoverable`, `indexable` and `bot` (the actor flags),
	 * `source[privacy]` (the default audience) and `fields_attributes` (the
	 * profile metadata). Returns the updated account entity.
	 *
	 * Every field is optional and only what was sent is written, which is what
	 * lets a client that edits one thing leave the rest alone. Three of them
	 * used to be accepted and dropped — `display_name`, `avatar` and `bot` —
	 * so a client's profile editor, which sends the whole form in one PATCH,
	 * got a 200 and showed the name and picture unchanged.
	 *
	 * The name and the picture belong to the Nextcloud account rather than to
	 * the actor, so they are written there and the actor cache is refreshed. A
	 * backend that owns either of them (LDAP, SAML, anything provisioned from
	 * elsewhere) makes this a **422** rather than a silent success: the profile
	 * looks the same afterwards either way, and only one of those two tells the
	 * user why.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v1/accounts/update_credentials')]
	public function updateCredentials(): DataResponse {
		try {
			$this->initViewer(true);

			$changed = false;
			$input = $this->convertInput(file_get_contents('php://input'));
			if (array_key_exists('locked', $input)) {
				$this->accountService->setLocked($this->currentSession(), $this->formBool($input['locked']));
				$changed = true;
			}

			// an absent `note` is a client that did not mention the bio, not a
			// client asking for an empty one
			if (array_key_exists('note', $input)) {
				$this->accountService->setSummary($this->currentSession(), (string)$input['note']);
				$changed = true;
			}

			// `source[privacy]` is the audience this account posts with when a
			// client does not name one, and `statusNew()` reads it back
			$privacy = $input['source']['privacy'] ?? null;
			if (is_string($privacy) && $privacy !== '') {
				$this->accountService->setDefaultPrivacy($this->currentSession(), $privacy);
				$changed = true;
			}

			// an absent display name is a client that did not mention it. A
			// backend that owns the name raises, and the client sees the
			// refusal rather than a 200 over an unchanged profile
			if (array_key_exists('display_name', $input)) {
				$this->accountService->setDisplayName(
					$this->currentSession(), (string)$input['display_name']
				);
				$changed = true;
			}

			// only the flags that were sent: a client updating the display
			// name must not reset the ones it did not mention
			$flags = [];
			foreach (['discoverable', 'indexable', 'bot'] as $flag) {
				if (array_key_exists($flag, $input)) {
					$flags[$flag] = $this->formBool($input[$flag]);
				}
			}
			if ($flags !== []) {
				$this->accountService->setActorFlags($this->currentSession(), $flags);
				$changed = true;
			}

			// Mastodon sends both pictures multipart on this same route: the
			// banner as `header`, the avatar as `avatar`. The avatar is the
			// Nextcloud account's picture — the same one the whole server shows
			// — so it is written there, and a backend that owns it raises
			// rather than answering 200 over an unchanged picture.
			$header = $_FILES['header'] ?? [];
			if ($header !== [] && ($header['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
				$this->bannerService->setFromTempFile($this->currentSession(), $header['tmp_name']);
				$changed = true;
			}

			$avatar = $_FILES['avatar'] ?? [];
			if ($avatar !== [] && ($avatar['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
				$this->avatarService->setFromTempFile($this->currentSession(), $avatar);
				$changed = true;
			}

			if (array_key_exists('fields_attributes', $input) && is_array($input['fields_attributes'])) {
				// clients send either a list or an object keyed by index
				$this->accountService->setFields(
					$this->currentSession(), array_values($input['fields_attributes'])
				);
				$changed = true;
			}

			if ($changed) {
				// refresh the viewer so the returned entity carries the change
				$this->viewer = $this->cacheActorService->getFromLocalAccount(
					$this->viewer->getPreferredUsername()
				);
				$this->viewer->setExportFormat(ACore::FORMAT_LOCAL);
			}

			return new DataResponse($this->accountEntity($this->viewer), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * A boolean as a Mastodon client sends it in a form or JSON body:
	 * `true`/`false`, `1`/`0`, or those as strings.
	 */
	private function formBool(mixed $value): bool {
		return in_array($value, [true, 1, '1', 'true'], true);
	}

	/**
	 * A local account as Mastodon's Account entity, with the gaps a brand-new
	 * account has filled the way Mastodon fills them.
	 *
	 * `Person::exportAsLocal()` writes `""` where it has nothing: for
	 * `last_status_at` when no post exists yet, for `avatar`/`header` while no
	 * icon is cached. Mastodon sends `null` for the date and never an empty
	 * image URL — a placeholder picture instead — and a strict decoder that
	 * expects a date or a URL there fails the whole Account, which is the
	 * first thing a client asks for after login. The stored source of the date
	 * is `AccountService::addLocalActorDetailCount()`, the export is
	 * `Person::exportAsLocal()`; until both emit what Mastodon does, this is
	 * where the credentials routes put it right.
	 *
	 * @return array<string, mixed>
	 */
	/**
	 * Mastodon's **CredentialAccount**: the Account entity plus `source`.
	 *
	 * Only the two credentials routes build one, because `source` is the
	 * account's own copy of its settings and `source.follow_requests_count` is
	 * nobody's business but theirs. It used to come out of the model on every
	 * Account this app emitted, including other people's and anonymous reads.
	 */
	private function accountEntity(Person $account): array {
		// the viewer is already in local format, see initViewer()
		$data = $account->jsonSerialize();
		$data['source'] = $account->exportSourceAsLocal();

		// the default audience is the one setting this app keeps per user
		// rather than on the actor: the model has no way to know it
		if ($account->isLocal()) {
			$data['source']['privacy'] = $this->accountService->getDefaultPrivacy(
				$this->currentSession()
			);
		}

		if (($data['last_status_at'] ?? null) === '') {
			$data['last_status_at'] = null;
		}

		$placeholder = null;
		foreach (['avatar', 'avatar_static', 'header', 'header_static'] as $image) {
			if (($data[$image] ?? null) !== '') {
				continue;
			}

			$placeholder ??= $this->placeholderImage($account);
			$data[$image] = $placeholder;
		}

		return $data;
	}

	/**
	 * The picture shown for an account that has none cached yet: for a local
	 * account Nextcloud's own avatar, which every user has (generated from the
	 * initials when nothing was uploaded), the app icon otherwise.
	 */
	private function placeholderImage(Person $account): string {
		if ($account->isLocal()) {
			return $this->urlGenerator->linkToRouteAbsolute(
				'core.avatar.getAvatar', ['userId' => $account->getPreferredUsername(), 'size' => 128]
			);
		}

		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'social.svg')
		);
	}

	/**
	 * The accounts waiting for the viewer's approval to follow them.
	 *
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/follow_requests')]
	public function followRequests(): DataResponse {
		try {
			$this->initViewer(true);

			$accounts = $this->followService->getPendingRequests();
			foreach ($accounts as $account) {
				$account->setExportFormat(ACore::FORMAT_LOCAL);
			}

			return new DataResponse($accounts, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/follow_requests/{id}/authorize', requirements: ['id' => '.+'])]
	public function followRequestAuthorize(string $id): DataResponse {
		return $this->followRequestAction($id, true);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/follow_requests/{id}/reject', requirements: ['id' => '.+'])]
	public function followRequestReject(string $id): DataResponse {
		return $this->followRequestAction($id, false);
	}

	private function followRequestAction(string $id, bool $authorize): DataResponse {
		try {
			$this->initViewer(true);
			$follower = $this->resolveTargetAccount($id);

			if ($authorize) {
				$this->followService->authorizeFollowRequest($follower);
			} else {
				$this->followService->rejectFollowRequest($follower);
			}

			return new DataResponse(
				$this->followService->getRelationshipWith($follower), Http::STATUS_OK
			);
		} catch (FollowNotFoundException $e) {
			return new DataResponse(['error' => 'no pending follow request'], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/polls/{nid}')]
	public function pollGet(int $nid): DataResponse {
		try {
			$this->initViewer(true);

			$poll = $this->pollService->getPoll($nid, $this->viewer);

			return new DataResponse($this->pollService->exportPoll($poll), Http::STATUS_OK);
		} catch (StreamNotFoundException $e) {
			return new DataResponse(['error' => 'poll not found'], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Votes on a poll. On a poll from another server the choices go to its
	 * author as ActivityPub vote notes and the authoritative counts come back
	 * later as an Update from the origin; on a poll of this instance's own the
	 * vote is counted here and the new counts go out to the author's followers.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/polls/{nid}/votes')]
	public function pollVote(int $nid): DataResponse {
		try {
			$this->initViewer(true);

			$input = $this->convertInput(file_get_contents('php://input'));
			$choices = $input['choices'] ?? [];
			if (!is_array($choices)) {
				$choices = [$choices];
			}

			$poll = $this->pollService->vote($this->viewer, $nid, $choices);

			return new DataResponse($this->pollService->exportPoll($poll), Http::STATUS_OK);
		} catch (StreamNotFoundException $e) {
			return new DataResponse(['error' => 'poll not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidActionException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Files a moderation report about an account (and optionally some of its
	 * statuses) for the instance admins.
	 *
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/reports')]
	public function reportNew(): DataResponse {
		try {
			$this->initViewer(true);

			$input = $this->convertInput(file_get_contents('php://input'));
			$accountId = (string)($input['account_id'] ?? '');
			if ($accountId === '') {
				return new DataResponse(['error' => 'account_id is required'], Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			$target = $this->resolveTargetAccount($accountId);
			if ($target->getId() === $this->viewer->getId()) {
				return new DataResponse(['error' => 'you cannot report yourself'], Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			$statusIds = $input['status_ids'] ?? [];
			if (!is_array($statusIds)) {
				$statusIds = [$statusIds];
			}
			$statusIds = array_map('strval', $statusIds);

			$report = $this->reportService->reportFromLocal(
				$this->viewer,
				$target,
				$statusIds,
				(string)($input['comment'] ?? ''),
				(string)($input['category'] ?? Report::CATEGORY_OTHER),
				// Mastodon's `forward`: the report also goes to the instance
				// that hosts the account, which is the only one that can act
				// on it. Ignored for a local account -- ReportForwardService
				// decides, so no entry point can forward what must not be
				$this->formBool($input['forward'] ?? false)
			);
			$target->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($report, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's sign-up route, which this server does not have.
	 *
	 * An account here is a Nextcloud account: the server creates it, through
	 * whatever provisioning it is configured with, and this app is given one
	 * that already exists. So there is nothing for this route to create — and
	 * a **404** was the wrong way to say so, because a client reads it as "this
	 * server is broken" and shows nothing a person can act on.
	 *
	 * A 403 in Mastodon's own error shape is read, shown, and says where to go
	 * instead: the server's registration page when it has one, and otherwise
	 * that an administrator creates accounts here. `registrations: false` in
	 * the instance entity already says the same thing to a client that looks
	 * before it asks; this is for the one that asks.
	 *
	 * The approval queue, the invites and the email confirmation Mastodon
	 * builds on top of its sign-up are the server's too, for the same reason.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts')]
	public function accountNew(): DataResponse {
		return new DataResponse(
			[
				'error' => 'Account registration is not handled by this application',
				'details' => (object)[
					'base' => [
						(object)[
							'error' => 'ERR_BLOCKED',
							'description' => $this->registrationAdvice(),
						],
					],
				],
			],
			Http::STATUS_FORBIDDEN
		);
	}

	/** Where somebody who wanted to sign up should be sent instead. */
	private function registrationAdvice(): string {
		if ($this->appManager->isEnabledForUser('registration')) {
			return 'An account on this server is a Nextcloud account. Sign up at '
				. $this->urlGenerator->getAbsoluteURL('/apps/registration/')
				. ' and this application will give that account a fediverse identity.';
		}

		return 'An account on this server is a Nextcloud account, created by an '
			. 'administrator. Once it exists, this application gives it a fediverse '
			. 'identity; there is nothing to sign up for here.';
	}

	/**
	 * The emoji this instance publishes.
	 *
	 * Answered `[]` unconditionally until the instance had any: emoji from
	 * every other server rendered here and this one could publish none, which
	 * is the asymmetry somebody moving here notices first. Only the ones
	 * marked visible are listed — that is what the field means, and a picker
	 * is what reads this route.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/custom_emojis')]
	public function customEmojis(): DataResponse {
		return new DataResponse($this->emojiService->visible(), Http::STATUS_OK);
	}

	/**
	 * The picture behind a shortcode.
	 *
	 * Unauthenticated, like `mediaOpen()` and for the same reason: this is
	 * what a remote server dereferences out of an `Emoji` tag on a post it
	 * received, and it has no token of ours to present. An emoji is published
	 * by definition — it is on every post that uses it, everywhere that post
	 * went — so there is nothing here to keep from anybody.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/emoji/{shortcode}')]
	public function emojiOpen(string $shortcode): Response {
		try {
			$emoji = $this->emojiService->byShortcode($shortcode);
			if ($emoji === null) {
				return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
			}

			$response = new FileDisplayResponse(
				$this->emojiService->picture($shortcode),
				Http::STATUS_OK,
				['Content-Type' => $emoji->getMediaType()]
			);
			// the shortcode names one picture and replacing it is a deliberate
			// act, so a day is cheap; a shared cache may keep it, since the
			// route answers everybody the same bytes
			$response->cacheFor(86400, false, true);

			return $response;
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/saved_searches/list.json')]
	public function savedSearches(): DataResponse {
		try {
			$this->initViewer(true);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's V1::Instance entity — the first request every client makes.
	 *
	 * @throws InstanceDoesNotExistException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/')]
	public function instance(): DataResponse {
		$local = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);

		return new DataResponse($local, Http::STATUS_OK);
	}

	/**
	 * The instance's rules, as their own resource.
	 *
	 * They were already served *inside* the instance entity, out of the `rules`
	 * app value — so the data was here and the route a client reads it from was
	 * a 404. An instance that has set none answers `[]`, which is the truthful
	 * answer and not an error.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/rules')]
	public function instanceRules(): DataResponse {
		return new DataResponse(
			$this->instanceService->getLocal(Stream::FORMAT_LOCAL)->getRules(),
			Http::STATUS_OK
		);
	}

	/**
	 * The instances this one has decided not to federate with.
	 *
	 * Mastodon publishes the deny list so that somebody choosing a server can
	 * see who it will not talk to. That is a disclosure decision rather than a
	 * lookup, so it is one an admin makes: with `publish_blocks` unset — the
	 * default — this answers `[]`, which is what an instance that has not opted
	 * in should say rather than refusing and inviting a client to guess.
	 *
	 * Only ever the deny list. In allow-list mode the same column holds the
	 * instances this server *does* talk to, and publishing that as a block list
	 * would be exactly backwards.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/domain_blocks')]
	public function instanceDomainBlocks(): DataResponse {
		if ($this->configService->getAppValue(ConfigService::SOCIAL_PUBLISH_BLOCKS) !== '1'
			|| $this->fediverseService->getAccessType() !== 'all_but') {
			return new DataResponse([], Http::STATUS_OK);
		}

		$blocks = [];
		foreach ($this->fediverseService->getListedAddresses() as $domain) {
			// `digest` is Mastodon's sha256 of the domain, `severity` the only
			// one this list has, and `comment` is not stored here
			$blocks[] = [
				'domain' => $domain,
				'digest' => hash('sha256', $domain),
				'severity' => 'suspend',
				'comment' => '',
			];
		}

		return new DataResponse($blocks, Http::STATUS_OK);
	}

	/**
	 * The long form of what this instance is, as Mastodon's
	 * `ExtendedDescription`.
	 *
	 * Taken from the `extended_description` app value, and falling back to the
	 * short description the instance entity already carries — an empty page
	 * where a server has written a description elsewhere is worse than
	 * repeating it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/extended_description')]
	public function instanceExtendedDescription(): DataResponse {
		$text = trim($this->configService->getAppValue(ConfigService::SOCIAL_EXTENDED_DESCRIPTION));
		$instance = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);

		return new DataResponse([
			'updated_at' => gmdate('Y-m-d\TH:i:s') . '.000Z',
			'content' => $text === '' ? $instance->getDescription() : $text,
		], Http::STATUS_OK);
	}

	/**
	 * Mastodon's V2::Instance entity. Newer clients ask for this one first and
	 * fall back to v1 on a 404; answering it saves them the round trip.
	 *
	 * @throws InstanceDoesNotExistException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/instance')]
	public function instanceV2(): DataResponse {
		$local = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);

		return new DataResponse($local->asV2(), Http::STATUS_OK);
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/statuses')]
	public function statusNew(): DataResponse {
		try {
			$this->initViewer(true);

			$input = file_get_contents('php://input');
			$this->logger->debug('[ApiController] statusNew: ' . $input);

			$data = $this->convertInput($input);
			$status = new Status();
			$status->import($data);

			// A `scheduled_at` is not a slow post: Mastodon answers it with a
			// ScheduledStatus entity and publishes nothing until the time
			// comes. The field used to be parsed and ignored, so a post
			// scheduled for next Tuesday went out at once -- and the client was
			// told it had been scheduled. This sits before the idempotency
			// lookup, which remembers a published status by nid and has nothing
			// to remember here.
			if ($this->scheduledStatusService->requestedTime($data) > 0) {
				return new DataResponse(
					$this->scheduledStatusService->schedule(
						$this->accountService->getActorFromUserId($this->currentSession(), true),
						$status,
						$data
					),
					Http::STATUS_OK
				);
			}

			// Tusky and Ivory send an Idempotency-Key and retry the post when
			// the connection drops, so on a flaky mobile link the same post used
			// to be created — and federated to every follower — several times.
			$idempotencyKey = $this->idempotencyKey();
			$already = $this->statusForIdempotencyKey($idempotencyKey);
			if ($already !== null) {
				return new DataResponse($already, Http::STATUS_OK);
			}

			// Use the viewer that was already initialized
			$actor = $this->accountService->getActorFromUserId($this->currentSession(), true);
			$post = new Post($actor);
			$post->setContent($status->getStatus());
			$post->setPoll($status->getPoll());
			$post->setSpoilerText($status->getSpoilerText());
			$post->setSensitive($status->isSensitive());
			$post->setType($this->visibilityOf($status));
			$post->setLanguage($status->getLanguage());
			$post->setPlaceId(
				$this->placeService->resolve(
					$status->getPlaceId(),
					$status->getPlaceName(),
					$status->getPlaceCountry(),
					$status->getPlaceLat(),
					$status->getPlaceLon()
				)?->getId() ?? 0
			);

			if (!empty($status->getMediaIds())) {
				$documents = $this->documentService->getMediaFromArray(
					$status->getMediaIds(),
					$this->viewer->getPreferredUsername()
				);
				$this->scopeMediaToVisibility($documents, $post->getType());
				$post->setMedias(
					array_map(function (Document $document): MediaAttachment {
						return $document->convertToMediaAttachment(
							$this->urlGenerator,
							ACore::FORMAT_ACTIVITYPUB
						);
					}, $documents)
				);
			}

			if ($status->getInReplyToId() > 0) {
				try {
					$replyTo = $this->streamService->getStreamByNid($status->getInReplyToId());
					$post->setReplyTo($replyTo->getId());
				} catch (StreamNotFoundException $e) {
					$this->logger->debug('reply to post not found');
				}
			}

			$post->setQuotedId($status->getQuotedId());
			$activity = $this->postService->createPost($post);

			$item = $this->streamService->getStreamById(
				$activity->getObjectId(),
				true,
				ACore::FORMAT_LOCAL
			);

			$this->rememberIdempotencyKey($idempotencyKey, $item->getNid());

			$this->logger->info('[ApiController] Status created successfully', [
				'postId' => $activity->getObjectId()
			]);

			return new DataResponse($item, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The visibility a new status is posted with.
	 *
	 * A client that leaves the field out means "whatever this account posts
	 * with", which is the account's own default — `source.privacy`, set through
	 * `/api/v1/accounts/update_credentials` and `public` until someone changes
	 * it. Anything this app does not know is refused rather than posted:
	 * `Stream::visibilityFromClient()` maps an unknown value to `direct`, and a
	 * direct message gets no recipient added, so those posts used to answer 200
	 * and be delivered to nobody.
	 *
	 * @throws InvalidActionException
	 */
	private function visibilityOf(Status $status): string {
		$visibility = trim($status->getVisibility());
		if ($visibility === '') {
			return $this->accountService->getDefaultPrivacy($this->currentSession());
		}

		if (!Stream::isKnownClientVisibility($visibility)) {
			throw new InvalidActionException('unknown visibility: ' . $visibility);
		}

		return $visibility;
	}

	/**
	 * Records on a post's attachments whether the post itself is world-readable.
	 *
	 * The `public` flag on a cached document is a hint about the audience, not
	 * access control: `/media/{uuid}` serves any local copy to whoever holds its
	 * unguessable uuid, the way Mastodon does (see `mediaOpen()`), because that
	 * is how remote servers fetch attachments for their own readers. What the
	 * flag decides is how the bytes may be cached on the way — a shared proxy
	 * may keep a public attachment, only the reader's browser a non-public one.
	 * Which post an upload belongs to is only known when that post is created,
	 * which is where this runs.
	 *
	 * @param Document[] $documents
	 */
	private function scopeMediaToVisibility(array $documents, string $visibility): void {
		$public = in_array($visibility, [Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED], true);

		foreach ($documents as $document) {
			if ($document->isPublic() === $public) {
				continue;
			}

			$document->setPublic($public);
			$this->cacheDocumentsRequest->update($document);
		}
	}

	/**
	 * This request's Idempotency-Key, scoped to whoever sent it.
	 *
	 * The key is only meaningful together with the credential that used it —
	 * two clients are free to pick the same one — so what is stored is a digest
	 * of both, which also keeps the bearer token itself out of the cache.
	 */
	private function idempotencyKey(): string {
		$key = trim($this->request->getHeader('Idempotency-Key'));
		if ($key === '') {
			return '';
		}

		$owner = ($this->bearer !== '') ? $this->bearer : (string)$this->viewer?->getId();

		return hash('sha256', $owner . '|' . $key);
	}

	/**
	 * The status a previous request with this Idempotency-Key created, if it is
	 * still on record and still exists.
	 */
	private function statusForIdempotencyKey(string $key): ?Stream {
		if ($key === '') {
			return null;
		}

		$nid = $this->cacheFactory->createDistributed(self::IDEMPOTENCY_CACHE)->get($key);
		if (!is_numeric($nid) || (int)$nid < 1) {
			return null;
		}

		try {
			$item = $this->streamService->getStreamByNid((int)$nid);
		} catch (Exception $e) {
			// deleted since, or never really written: let the post go through
			return null;
		}

		$item->setExportFormat(ACore::FORMAT_LOCAL);

		return $item;
	}

	private function rememberIdempotencyKey(string $key, int $nid): void {
		if ($key === '' || $nid < 1) {
			return;
		}

		$this->cacheFactory->createDistributed(self::IDEMPOTENCY_CACHE)
			->set($key, $nid, self::IDEMPOTENCY_TTL);
	}

	/**
	 *
	 * @param int $nid
	 *
	 * @return DataResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/statuses/{nid}')]
	public function statusUpdate(int $nid): DataResponse {
		try {
			$this->initViewer(true);

			$input = file_get_contents('php://input');
			$status = new Status();
			$status->import($this->convertInput($input));

			$actor = $this->accountService->getActorFromUserId($this->currentSession(), true);

			$item = $this->postService->editPost(
				$nid,
				$actor,
				$status->getStatus(),
				$status->getSpoilerText() !== '' ? $status->getSpoilerText() : null,
				$status->isSensitive(),
				$status->getLanguage() !== '' ? $status->getLanguage() : null
			);
			$item->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($item, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/media')]
	public function mediaNew(): DataResponse {
		try {
			$this->initViewer(true);

			$file = $_FILES['file'] ?? [];
			if (empty($file)) {
				throw new InvalidActionException('no media found');
			}

			if ($file['error'] !== UPLOAD_ERR_OK) {
				throw new InvalidActionException('error during upload');
			}

			$name = $file['tmp_name'] ?? '';
			$size = $file['size'] ?? -1;
			$type = $file['type'] ?? '';

			if ($name === '' || $size === -1 || $type === '') {
				throw new InvalidActionException('missing details');
			}

			// The same ceiling the app puts on anything it downloads, applied
			// to what it is handed: without it an upload was bounded only by
			// PHP's own limits, and the file is read into memory to be hashed,
			// sniffed and (for an image) decoded.
			$maxSize = $this->instanceService->maxUploadSize();
			if ($size > $maxSize) {
				throw new InvalidActionException(
					'file is larger than the ' . (int)($maxSize / 1048576) . 'MB limit'
				);
			}

			$this->logger->debug('[ApiController] mediaNew: ' . json_encode($file));

			return new DataResponse(
				$this->storeAttachment(
					$name,
					(string)$this->request->getParam('description', ''),
					(string)$this->request->getParam('focus', '')
				),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Attaches a file the viewer already has in Nextcloud.
	 *
	 * Not a Mastodon route: the point of running this app inside a Nextcloud is
	 * that the pictures are already here, and making somebody download their
	 * own photo and upload it back is the one thing no other Fediverse server
	 * has an excuse for. The file is copied, not referenced — a post keeps the
	 * picture it was published with, so moving or deleting the original later
	 * cannot empty a post that is already federated, and the attachment is
	 * scoped to the post's visibility the same way an upload is.
	 *
	 * The path is resolved inside the viewer's own user folder and nowhere
	 * else, so a share they can read is fair game and everything else is a 404.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/media/from-file')]
	public function mediaFromFile(): DataResponse {
		try {
			$this->initViewer(true);

			$input = $this->convertInput(file_get_contents('php://input'));
			$path = trim((string)($input['path'] ?? $this->request->getParam('path', '')));
			if ($path === '') {
				throw new InvalidActionException('no file named');
			}

			$file = $this->ownFile($this->currentSession(), $path);

			// the ceiling an upload is held to, applied to the same bytes: the
			// file is read into memory to be hashed, sniffed and decoded
			$maxSize = $this->instanceService->maxUploadSize();
			if ($file->getSize() > $maxSize) {
				throw new InvalidActionException(
					'file is larger than the ' . (int)($maxSize / 1048576) . 'MB limit'
				);
			}

			$description = (string)($input['description'] ?? $this->request->getParam('description', ''));

			// through a temp file, so the mime sniffing, the size guard and the
			// resizing are the same code an upload goes through rather than a
			// second path that could drift from it
			$tmpPath = $this->tempManager->getTemporaryFile();
			if ($tmpPath === false) {
				throw new InvalidActionException('no temporary file to copy into');
			}

			$handle = $file->fopen('r');
			if ($handle === false) {
				throw new InvalidActionException('the file could not be read');
			}

			try {
				if (file_put_contents($tmpPath, $handle) === false) {
					throw new InvalidActionException('the file could not be copied');
				}
			} finally {
				fclose($handle);
			}

			return new DataResponse($this->storeAttachment($tmpPath, $description), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * One file out of the viewer's own storage.
	 *
	 * `getUserFolder()` is the boundary: a path is resolved relative to it, so
	 * a traversal leaves the folder and is not found. The containment check
	 * after the lookup says so a second time rather than trusting that — this
	 * route names a file and returns its contents, which is exactly the shape
	 * a mistake here would be exploited in.
	 *
	 * @throws InvalidActionException
	 */
	private function ownFile(string $userId, string $path): File {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$node = $userFolder->get($path);
		} catch (Throwable $e) {
			throw new InvalidActionException('no such file');
		}

		if (!$node instanceof File) {
			throw new InvalidActionException('that is not a file');
		}

		if ($userFolder->getRelativePath($node->getPath()) === null) {
			throw new InvalidActionException('no such file');
		}

		return $node;
	}

	/**
	 * Stores a local file as one of the viewer's attachments.
	 *
	 * @return MediaAttachment the entity a client is answered with
	 */
	private function storeAttachment(string $tmpPath, string $description, string $focus = ''): MediaAttachment {
		$document = new Document();
		$document->setLocal(true);
		$document->setAccount($this->viewer->getPreferredUsername());
		$document->setUrlCloud($this->configService->getCloudUrl());
		$document->generateUniqueId('/documents/local');
		// Not public until a post says so. `public` decides whether the
		// unauthenticated /media/{uuid} route serves the file, and this used
		// to be set on every upload — so an attachment to a direct message
		// was, by the row's own account, readable by anybody. The visibility
		// is applied when the status is created; see scopeMediaToVisibility().
		$document->setPublic(false);
		$document->setDescription($description);

		// Where the subject is, so a crop keeps it in frame. Nonsense is
		// ignored rather than refused: a client that sends a malformed focus
		// has still sent a picture, and losing the upload over it would be a
		// worse answer than centring it.
		$parsed = ($focus === '') ? null : Document::parseFocus($focus);
		if ($parsed !== null) {
			$document->setFocus($parsed[0], $parsed[1]);
		}

		$this->cacheDocumentService->saveFromTempToCache($document, $tmpPath);
		$service = AP::instance()->getInterfaceForItem($document);
		$service->save($document);

		$mediaAttachment = $document->convertToMediaAttachment($this->urlGenerator);

		$this->logger->debug('generated attachment: ' . json_encode($mediaAttachment));

		return $mediaAttachment;
	}

	/**
	 * Same upload as mediaNew — modern Mastodon clients POST /api/v2/media and
	 * only fall back to v1 on a 404.
	 *
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v2/media')]
	public function mediaNewV2(): DataResponse {
		return $this->mediaNew();
	}

	/**
	 * One of the viewer's own attachments, by the id mediaNew returned.
	 *
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/media/{nid}')]
	public function mediaGet(string $nid, string $preview = ''): Response {
		try {
			$this->initViewer(true);

			return new DataResponse($this->ownAttachment($nid), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Updates the alt text or the focal point of the viewer's own attachment.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/media/{nid}')]
	public function mediaUpdate(string $nid): Response {
		try {
			$this->initViewer(true);

			$document = $this->ownDocument($nid);
			$input = $this->convertInput(file_get_contents('php://input'));
			if (array_key_exists('description', $input)) {
				$document->setDescription((string)$input['description']);
				$this->documentService->updateDescription($document);
			}
			if (array_key_exists('focus', $input)) {
				$parsed = Document::parseFocus((string)$input['focus']);
				if ($parsed !== null) {
					$this->documentService->updateFocus($document, $parsed[0], $parsed[1]);
				}
			}

			return new DataResponse(
				$document->convertToMediaAttachment($this->urlGenerator), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * @throws NotFoundException when the id is unknown or belongs to someone else
	 */
	private function ownDocument(string $nid): Document {
		$documents = $this->documentService->getMediaFromArray(
			[$nid], $this->viewer->getPreferredUsername()
		);
		if (count($documents) !== 1) {
			throw new NotFoundException('unknown media');
		}

		return $documents[0];
	}

	/**
	 * @throws NotFoundException
	 */
	private function ownAttachment(string $nid): MediaAttachment {
		return $this->ownDocument($nid)->convertToMediaAttachment($this->urlGenerator);
	}

	/**
	 *
	 * @param string $id
	 *
	 * @return Response
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/media/{uuid}')]
	public function mediaOpen(string $uuid): Response {
		if (strpos($uuid, '.') > 0) {
			[$uuid] = explode('.', $uuid, 2);
		}

		try {
			// Any local copy is served to whoever holds its uuid. The route is
			// unauthenticated on purpose: this is how Mastodon and every other
			// fediverse server fetch media — unsigned, on behalf of a reader they
			// have already checked — so restricting it to rows flagged `public`
			// (as this used to) only meant a broken image under every
			// followers-only or direct post with a picture. The uuid is a v4 from
			// random_bytes(), unguessable, handed only to the post's audience;
			// see DocumentService::getFromUuid() for the model.
			[$file, $document] = $this->documentService->getFromUuid($uuid);

			// The stored media type was sniffed from the content at ingest; the
			// extension in the URL is whatever the requester chose to write there.
			$response = new FileDisplayResponse(
				$file, Http::STATUS_OK, ['Content-Type' => $document->getMediaType()]
			);

			// The bytes behind a uuid never change, so they may be kept for good —
			// but only a browser's own cache may keep a non-public one: a shared
			// proxy in front of this instance would otherwise answer the same URL
			// to anyone, which is a wider audience than "whoever was sent it".
			$response->cacheFor(self::MEDIA_CACHE_SECONDS, $document->isPublic(), true);

			return $response;
		} catch (NotFoundException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Exception $e) {
			$this->logger->warning('issues while mediaOpen', ['exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * A federated video, passed through from the instance that holds it.
	 *
	 * The page may not point a `<video>` at another server -- Nextcloud's
	 * content security policy says `media-src 'self'` -- and even where it
	 * could, every reader who pressed play would be introducing themselves to
	 * a host they had never chosen to talk to. So the bytes come through here
	 * instead, a chunk at a time, stored nowhere; see `StreamedRemoteResponse`.
	 *
	 * Unauthenticated, like `mediaOpen()` and for the same reason: this is a
	 * media url, and it is handed out with the post it belongs to. What keeps
	 * it from being a proxy for the whole internet is that it takes a row id
	 * rather than a url, and the row has to be one this app wrote as streamed.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	// generous, because one video is many requests: a player asks for the
	// first megabyte, then the moov atom at the other end of the file, then a
	// range per seek. A limit sized for an API call would stop playback in the
	// middle, which is indistinguishable from a broken video
	#[AnonRateLimit(limit: 120, period: 60)]
	#[UserRateLimit(limit: 600, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/media/stream/{nid}')]
	public function mediaStream(int $nid): Response {
		try {
			$opened = $this->documentService->openStreamed(
				$nid, $this->request->getHeader('Range')
			);
			/** @var Document $document */
			$document = $opened['document'];

			$headers = [
				'Content-Type' => $document->getMediaType(),
				// what makes a player offer a seek bar at all
				'Accept-Ranges' => 'bytes',
				// the bytes behind a row never change, and the row is only
				// named by the post it hangs off
				'Cache-Control' => 'private, max-age=' . self::MEDIA_CACHE_SECONDS,
				// this is a file to play, never a document to interpret: the
				// origin's own type is not repeated to the browser as a
				// licence to sniff
				'X-Content-Type-Options' => 'nosniff',
			];

			// the two the origin answered that a player needs to make sense of
			// a partial answer, and nothing else it happened to send
			foreach (['Content-Length', 'Content-Range'] as $header) {
				$value = $opened['headers'][$header] ?? $opened['headers'][strtolower($header)] ?? [];
				if ($value !== []) {
					$headers[$header] = (string)$value[0];
				}
			}

			return new StreamedRemoteResponse($opened['stream'], $opened['status'], $headers);
		} catch (NotFoundException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Exception $e) {
			$this->logger->warning('issues while mediaStream', ['exception' => $e]);

			return new DataResponse(['error' => 'could not reach the origin'], Http::STATUS_BAD_GATEWAY);
		}
	}

	/**
	 *
	 * @param string $timeline
	 * @param bool $local
	 * @param int $limit
	 * @param int $max_id
	 * @param int $min_id
	 * @param int $since_id
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/timelines/{timeline}/')]
	public function timelines(
		string $timeline,
		bool $local = false,
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since_id = 0,
		bool $only_media = false,
		bool $only_video = false,
	): DataResponse {
		$this->logger->info('[ApiController] timelines called', [
			'timeline' => $timeline,
			'local' => $local,
			'limit' => $limit,
			'max_id' => $max_id,
			'min_id' => $min_id,
			'since_id' => $since_id
		]);
		try {
			// Mastodon's public timeline is readable without a token, and a
			// client asks for it before it has one — to show the reader what is
			// here before they log in. Every other timeline is about somebody,
			// so it still needs a viewer. A token that *was* presented still has
			// to be a good one: a client whose token has been revoked has to
			// learn that, not quietly get the anonymous view instead.
			$this->initViewer(
				$this->bearer !== '' || strtolower($timeline) !== ProbeOptions::PUBLIC
			);
			$this->logger->debug('[ApiController] Viewer initialized', [
				'viewerId' => $this->viewer?->getId()
			]);

			if (!in_array(
				strtolower($timeline),
				[
					ProbeOptions::HOME,
					ProbeOptions::ACCOUNT,
					ProbeOptions::PUBLIC,
					ProbeOptions::DIRECT,
					ProbeOptions::FAVOURITES
				]
			)) {
				$this->logger->error('[ApiController] Unknown timeline requested', [
					'timeline' => $timeline
				]);
				throw new UnknownProbeException('unknown timeline');
			}

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe($timeline)
				->setLocal($local)
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id)
				->setOnlyMedia($only_media)
				->setOnlyVideo($only_video);

			$posts = $this->streamService->getTimeline($options);
			$this->logger->info('[ApiController] Timeline retrieved', [
				'timeline' => $timeline,
				'postsCount' => count($posts)
			]);

			// the unfiltered page is what says whether a further page exists: a
			// page shortened by a `hide` filter says nothing about what is older
			return $this->paged(
				$this->filterService->apply($posts, $this->filterContext($timeline), $this->viewer),
				$options->getLimit(),
				$posts
			);
		} catch (Throwable $e) {
			$this->logger->error('[ApiController] Timeline request failed', [
				'timeline' => $timeline,
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			return $this->error($e);
		}
	}

	/**
	 *
	 * @param int $nid
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}')]
	public function statusGet(int $nid): DataResponse {
		try {
			$this->initViewer(false);

			$item = $this->streamService->attachCard($this->streamService->getStreamByNid($nid));
			$item->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($item, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The link preview of one status, on its own.
	 *
	 * The card is already inlined in the status entity, which is what most
	 * clients read; this route was a **405** rather than a 404, because the
	 * path matched the POST-only action route below and nothing answered a
	 * GET. A status with no link answers `{}` — Mastodon's own answer, and not
	 * an error: most statuses have no card.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/card')]
	public function statusCard(int $nid): DataResponse {
		try {
			$this->initViewer(false);

			$card = $this->streamService
				->attachCard($this->streamService->getStreamByNid($nid))
				->getCard();

			return new DataResponse($card ?? (object)[], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @param int $nid
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/context')]
	public function statusContext(int $nid): DataResponse {
		try {
			$this->initViewer(false);
			$context = $this->streamService->getContextByNid($nid);

			return new DataResponse(
				[
					'ancestors' => $this->filterService->apply(
						$context['ancestors'] ?? [], Filter::CONTEXT_THREAD, $this->viewer
					),
					'descendants' => $this->filterService->apply(
						$context['descendants'] ?? [], Filter::CONTEXT_THREAD, $this->viewer
					),
				],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Deletes one of the viewer's own statuses.
	 *
	 * The app's own frontend has always had a delete (`DELETE /api/v1/post`,
	 * behind the session and a CSRF token), but a client holding a bearer token
	 * had no way to reach it: the route Mastodon deletes with did not exist, so
	 * every client's delete button failed. Mastodon answers with the status that
	 * was removed — that is what "delete & redraft" puts back in the composer.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/statuses/{nid}')]
	public function statusDelete(int $nid): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActorFromUserId($this->currentSession(), true);

			$item = $this->streamService->getStreamByNid($nid);
			if ($item->getAttributedTo() !== $actor->getId()) {
				// the same answer an unknown id gets: whether somebody else's
				// post exists is not this route's to tell
				throw new StreamNotFoundException('Stream not found');
			}

			// exported before the delete, while the row is still there to read
			$item->setExportFormat(ACore::FORMAT_LOCAL);
			$deleted = $item->exportAsLocal();

			$this->streamService->deleteLocalItem($item, $item->getType());

			return new DataResponse($deleted, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The text of one of the viewer's own statuses, as it was written.
	 *
	 * Mastodon's StatusSource entity. Tusky and Ivory will not offer an edit
	 * button without it, even though `PUT /api/v1/statuses/{id}` has worked
	 * here all along: they fetch the source first, to have something to put in
	 * the editor. The stored content is the HTML that was rendered from the
	 * original text, so it is turned back: the line breaks that `nl2br()` wrote
	 * become newlines again and the entities are decoded.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/source')]
	public function statusSource(int $nid): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActorFromUserId($this->currentSession(), true);

			$item = $this->streamService->getStreamByNid($nid);
			if ($item->getAttributedTo() !== $actor->getId()) {
				throw new StreamNotFoundException('Stream not found');
			}

			return new DataResponse(
				[
					'id' => (string)$item->getNid(),
					'text' => $this->asSourceText($item->getContent()),
					'spoiler_text' => $item->getSpoilerText(),
				], Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The rendered content of a status, back as close to its source as it goes. */
	private function asSourceText(string $content): string {
		$text = preg_replace('#<br\s*/?>#i', "\n", $content);
		$text = strip_tags((string)$text);

		return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	/**
	 *
	 * @param int $nid
	 * @param string $action
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/statuses/{nid}/{act}')]
	public function statusAction(int $nid, string $act): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActor($this->viewer->getPreferredUsername());
			$item = $this->actionService->action($actor, $nid, $act);

			if ($item === null) {
				$item = $this->streamService->getStreamByNid($nid);
			}

			$item->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($item, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The posts this account has asked to have published later, soonest first.
	 *
	 * No `Link` header: a ScheduledStatus is not a Stream and carries no nid
	 * for `paged()` to page on. The three cursors are honoured, so a client
	 * that builds its own still pages.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/scheduled_statuses')]
	public function scheduledStatuses(
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since_id = 0,
	): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActorFromUserId($this->currentSession(), true);

			return new DataResponse(
				$this->scheduledStatusService->getAll($actor, $limit, $max_id, $min_id, $since_id),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** One waiting post. One that is not the viewer's is a 404, not a refusal. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/scheduled_statuses/{id}')]
	public function scheduledStatusGet(int $id): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActorFromUserId($this->currentSession(), true);

			return new DataResponse($this->scheduledStatusService->getOne($actor, $id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Moves a waiting post to another time; the five-minute rule applies again. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/scheduled_statuses/{id}')]
	public function scheduledStatusUpdate(int $id): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActorFromUserId($this->currentSession(), true);
			$data = $this->convertInput(file_get_contents('php://input'));

			return new DataResponse(
				$this->scheduledStatusService->reschedule($actor, $id, $data), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Cancels a waiting post. Mastodon answers an empty object. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/scheduled_statuses/{id}')]
	public function scheduledStatusDelete(int $id): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActorFromUserId($this->currentSession(), true);
			$this->scheduledStatusService->delete($actor, $id);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The accounts that favourited a status, newest first.
	 *
	 * Mastodon's `favourited_by`, and the reason a tap on a favourite count is
	 * not a dead end. The status is resolved through the visibility filter
	 * first, so one the caller may not read is a **404** and no reaction of it
	 * is looked at — the list of who liked a post is as private as the post.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/favourited_by')]
	public function statusFavouritedBy(int $nid, int $limit = 40): DataResponse {
		return $this->reactedBy($nid, Like::TYPE, $limit);
	}

	/** The accounts that boosted a status, newest first. Mastodon's `reblogged_by`. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/reblogged_by')]
	public function statusRebloggedBy(int $nid, int $limit = 40): DataResponse {
		return $this->reactedBy($nid, Announce::TYPE, $limit);
	}

	private function reactedBy(int $nid, string $type, int $limit): DataResponse {
		try {
			$this->initViewer(false);
			$limit = min(max($limit, 1), 80);
			$post = $this->streamService->getStreamByNid($nid);

			return new DataResponse(
				$this->actionService->reactedBy($post, $type, $limit), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's account search: what a composer calls to complete a `@handle`
	 * as somebody types it.
	 *
	 * `/api/v2/search` answers accounts too, but no client uses it for
	 * autocomplete — they call this one, and this app did not have it, so
	 * mention completion failed in every client that offers it.
	 *
	 * `resolve` asks this instance to go and find an account it has never seen,
	 * which is what makes completing a handle from another server work at all.
	 * It fires only for a viewer, and only for something shaped like an
	 * address or a handle, the same rule `/api/v2/search` follows.
	 *
	 * `following` narrows the answer to accounts the viewer follows, which is
	 * what a client asks for when it is completing a reply rather than a search.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/search')]
	public function accountsSearch(
		string $q = '',
		int $limit = 40,
		bool $resolve = false,
		bool $following = false,
	): DataResponse {
		try {
			$this->initViewer(true);
			$q = trim($q);
			$limit = min(max($limit, 1), 80);

			if ($q === '') {
				return new DataResponse([], Http::STATUS_OK);
			}

			$found = $this->searchService->searchAccounts($q, $limit);
			if ($resolve && (str_starts_with($q, '@') || str_starts_with($q, 'http'))) {
				$found = array_merge($this->searchService->searchUri($q), $found);
			}

			$accounts = [];
			foreach ($found as $account) {
				$accounts[$account->getId()] = $account->setExportFormat(ACore::FORMAT_LOCAL);
			}
			$accounts = array_slice(array_values($accounts), 0, $limit);

			// `following=true` is a client completing a reply rather than
			// searching: it wants the people already in the conversation's
			// reach, not everybody this instance has ever cached
			if ($following) {
				$accounts = array_values(array_filter(
					$accounts,
					fn (Person $account): bool
						=> $this->followService->getRelationshipWith($account)->isFollowing()
				));
			}

			return new DataResponse(array_slice($accounts, 0, $limit), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The instances this one has heard of.
	 *
	 * Mastodon's `/api/v1/instance/peers`, which instance browsers and
	 * "about this server" pages read. A bare array of hostnames, which is what
	 * the peer of every cached remote actor amounts to, and the same walk the
	 * `domain_count` statistic uses — two walks would be two answers.
	 *
	 * Public, as Mastodon's is: it says who this instance federates with, not
	 * who its users are.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/peers')]
	public function instancePeers(): DataResponse {
		try {
			return new DataResponse($this->instanceService->getPeers(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's weekly activity series: twelve weeks of statuses, logins and
	 * registrations.
	 *
	 * `registrations` is always `0` and says so in the docs: an account here is
	 * a Nextcloud user, created by the server rather than by this app, so there
	 * is no registration for it to count. `logins` is likewise not this app's
	 * to know. What it does know is how many statuses were published in a week,
	 * which is the series a client actually plots.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/activity')]
	public function instanceActivity(): DataResponse {
		try {
			return new DataResponse($this->instanceService->getWeeklyActivity(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The viewer's own posting defaults, as Mastodon's `/api/v1/preferences`.
	 *
	 * Every value here is one the account already has somewhere — the default
	 * audience it posts with, whether its posts are marked sensitive, the
	 * language, and whether media and spoilers are expanded — and a client that
	 * cannot read them guesses, which is how a client ends up posting publicly
	 * for somebody whose default is followers-only.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/preferences')]
	public function preferences(): DataResponse {
		try {
			$this->initViewer(true);
			$source = $this->viewer->exportSourceAsLocal();

			return new DataResponse([
				'posting:default:visibility' => $this->accountService->getDefaultPrivacy(
					$this->currentSession()
				),
				'posting:default:sensitive' => (bool)($source['sensitive'] ?? false),
				'posting:default:language' => ($source['language'] ?? '') !== ''
					? $source['language'] : null,
				// this app has no per-account reading preferences; Mastodon's
				// defaults are what a client assumes when they are absent, so
				// sending them is what stops it assuming something else
				'reading:expand:media' => 'default',
				'reading:expand:spoilers' => false,
			], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Who, of the people the viewer follows, also follows each named account.
	 *
	 * Mastodon's `familiar_followers`, which draws the "followed by X and 3
	 * others you know" line on a profile. Absent, that line is simply missing
	 * from every profile a client shows.
	 *
	 * @param array<mixed>|string $id one or more account ids
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/familiar_followers')]
	public function familiarFollowers(array|string $id = []): DataResponse {
		try {
			$this->initViewer(true);
			$ids = is_array($id) ? $id : [$id];

			$familiar = [];
			foreach (array_slice($ids, 0, self::FAMILIAR_FOLLOWERS_MAX) as $one) {
				$target = $this->resolveTargetAccount((string)$one);
				$accounts = $this->followService->familiarFollowers($this->viewer, $target);
				$familiar[] = [
					'id' => (string)$target->getNid(),
					'accounts' => array_map(
						static fn (Person $account): Person => $account->setExportFormat(ACore::FORMAT_LOCAL),
						$accounts
					),
				];
			}

			return new DataResponse($familiar, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Follows the account, or asks to (a locked account leaves the
	 * relationship in `requested`). Returns the updated relationship.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts/{id}/follow', requirements: ['id' => '.+'])]
	public function accountFollow(string $id, ?bool $notify = null): DataResponse {
		try {
			$this->initViewer(true);
			$target = $this->resolveTargetAccount($id);

			$this->followService->followAccount($this->viewer, $target->getAccount());
			$this->accountService->cacheLocalActorDetailCount($this->viewer);

			// the bell on a profile, which Mastodon sends *with* the follow.
			// Absent means "leave it as it is": a client re-following to change
			// nothing else must not silently turn the bell off
			if ($notify !== null) {
				$this->accountRelationService->setNotify($this->viewer, $target, $notify);
			}

			return new DataResponse(
				$this->followService->getRelationshipWith($target), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts/{id}/unfollow', requirements: ['id' => '.+'])]
	public function accountUnfollow(string $id): DataResponse {
		try {
			$this->initViewer(true);
			$target = $this->resolveTargetAccount($id);

			$this->followService->unfollowAccount($this->viewer, $target->getAccount());
			$this->accountService->cacheLocalActorDetailCount($this->viewer);

			return new DataResponse(
				$this->followService->getRelationshipWith($target), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's v1 search, which is the v2 one without `statuses` being
	 * optional. `/api/v1/search` used to be the app's own web-UI search — a
	 * Nextcloud envelope, `content` where a client looks for `statuses`, `search=`
	 * where a client sends `q=`, and no bearer token accepted — so a client got
	 * a 200 it could make no sense of, which is worse than a 404. The web UI's
	 * own search now lives at `/local/v1/search`, beside its siblings.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/search')]
	public function search(string $q = '', string $type = '', int $limit = 20, bool $resolve = false): DataResponse {
		return $this->searchV2($q, $type, $limit, $resolve);
	}

	/**
	 * Mastodon's search endpoint: accounts, statuses (the viewer-bounded
	 * full-text search) and hashtags, optionally narrowed with `type`.
	 *
	 * `resolve` is accepted and ignored on purpose: it asks the server to go
	 * and fetch an account or status it has never seen, and every account
	 * search here is already `LIKE '%term%'` over the actor cache. Following an
	 * unknown handle up remotely on an anonymous request would make this route
	 * an outbound-fetch amplifier.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/search')]
	public function searchV2(string $q = '', string $type = '', int $limit = 20, bool $resolve = false): DataResponse {
		try {
			$this->initViewer(true);
			$q = trim($q);
			$limit = min(max($limit, 1), 40);

			$accounts = [];
			if ($type === '' || $type === 'accounts') {
				$found = array_merge(
					$this->searchService->searchUri($q),
					$this->searchService->searchAccounts($q)
				);
				$unique = [];
				foreach ($found as $account) {
					$unique[$account->getId()] = $account->setExportFormat(ACore::FORMAT_LOCAL);
				}
				$accounts = array_slice(array_values($unique), 0, $limit);
			}

			$statuses = [];
			if ($type === '' || $type === 'statuses') {
				$statuses = array_slice($this->searchService->searchStreamContent($q), 0, $limit);

				// `resolve` is the reader saying "I have a link, go and get
				// it". Without it a post found in a browser cannot be replied
				// to or boosted here, because nothing has ever had a reason to
				// ask its server for it. Only on the reader's say-so: this
				// fetches an address they chose.
				if ($resolve && $statuses === []) {
					$resolved = $this->searchService->resolveStatus($q);
					if ($resolved !== null) {
						$resolved->setExportFormat(ACore::FORMAT_LOCAL);
						$statuses = [$resolved];
					}
				}
			}

			$hashtags = [];
			if ($type === '' || $type === 'hashtags') {
				foreach (array_slice($this->searchService->searchHashtags($q), 0, $limit) as $hashtag) {
					$hashtags[] = [
						'name' => $hashtag['hashtag'],
						'url' => $this->urlGenerator->linkToRouteAbsolute(
							'social.Navigation.timeline', ['path' => 'tags/' . $hashtag['hashtag']]
						),
						'history' => [],
					];
				}
			}

			return new DataResponse(
				['accounts' => $accounts, 'statuses' => $statuses, 'hashtags' => $hashtags],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The hashtags used most on this instance lately, as Mastodon's Tag
	 * entities. The counts come from the trend the cron already keeps for
	 * every hashtag; this instance counts uses rather than distinct accounts,
	 * so `accounts` is always 0.
	 *
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/trends/tags')]
	public function trendTags(int $limit = 10, string $period = HashtagService::PERIOD_DEFAULT): DataResponse {
		try {
			$this->initViewer(false);
			$limit = max(1, min(20, $limit));

			// the same builder the tag lookup and the follow answers use, so a
			// Tag entity cannot mean one thing here and another there;
			// `following` is left out, as it must be on a public route
			$tags = [];
			foreach ($this->hashtagService->getTrending($limit, $period) as $hashtag) {
				$tags[] = $this->hashtagService->tagEntity($hashtag['hashtag'], null, $period);
			}

			return new DataResponse($tags, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts/{id}/block', requirements: ['id' => '.+'])]
	public function accountBlock(string $id): DataResponse {
		return $this->relationshipAction($id, 'block');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts/{id}/unblock', requirements: ['id' => '.+'])]
	public function accountUnblock(string $id): DataResponse {
		return $this->relationshipAction($id, 'unblock');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts/{id}/mute', requirements: ['id' => '.+'])]
	public function accountMute(string $id, bool $notifications = true, int $duration = 0): DataResponse {
		return $this->relationshipAction($id, 'mute', $notifications, $duration);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts/{id}/unmute', requirements: ['id' => '.+'])]
	public function accountUnmute(string $id): DataResponse {
		return $this->relationshipAction($id, 'unmute');
	}

	private function relationshipAction(
		string $id, string $action, bool $notifications = true, int $duration = 0,
	): DataResponse {
		try {
			$this->initViewer(true);
			$target = $this->resolveTargetAccount($id);

			switch ($action) {
				case 'block':
					$this->relationshipService->block($this->viewer, $target);
					break;
				case 'unblock':
					$this->relationshipService->unblock($this->viewer, $target);
					break;
				case 'mute':
					$this->relationshipService->mute($this->viewer, $target, $notifications);
					// duration 0 is Mastodon's "until I say otherwise", and it
					// drops the expiry a previous timed mute left behind
					$this->accountRelationService->setMuteExpiry($this->viewer, $target, $duration);
					break;
				case 'unmute':
					$this->relationshipService->unmute($this->viewer, $target);
					$this->accountRelationService->clearMuteExpiry($this->viewer, $target);
					break;
			}

			// Mastodon clients expect the updated relationship entity back
			$this->followService->setViewer($this->viewer);

			return new DataResponse(
				$this->followService->getRelationshipWith($target), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/blocks')]
	public function blocks(int $limit = 40): DataResponse {
		return $this->listRelatedAccounts(ActorRelation::TYPE_BLOCK, $limit);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/mutes')]
	public function mutes(int $limit = 40): DataResponse {
		return $this->listRelatedAccounts(ActorRelation::TYPE_MUTE, $limit);
	}

	private function listRelatedAccounts(string $type, int $limit): DataResponse {
		try {
			$this->initViewer(true);
			$limit = max(1, min(ProbeOptions::MAX_LIMIT, $limit));

			$related = $this->relationshipService->getRelated($this->viewer, $type, $limit);
			if ($type === ActorRelation::TYPE_MUTE) {
				// one query for the page: a mute whose expiry has passed is not
				// a mute, and nothing deleted the row to make that so
				$related = $this->accountRelationService->withoutExpiredMutes($this->viewer, $related);
			}

			$accounts = [];
			foreach ($related as $person) {
				$person->setExportFormat(ACore::FORMAT_LOCAL);
				$accounts[] = $person;
			}

			// No `Link` header: neither route takes a cursor, and the next page
			// `paged()` would advertise is the one just sent — a client paging
			// on the header scrolled the same block of blocked accounts for
			// ever. Answering one page is the honest shape until
			// RelationshipService can be asked for a cursored one.
			return new DataResponse($accounts, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * One account, by whatever reference the client holds.
	 *
	 * Every entity this app emits addresses an account by its numeric id, and
	 * until this route existed there was nothing to do with one: tapping an
	 * author, a mention, a boost or a notification asked for a profile that no
	 * route answered.
	 *
	 * The only route of this app that is not a `#[FrontpageRoute]`: `{id}`
	 * accepts slashes, so this url also matches `/api/v1/accounts/{account}/lists`
	 * and `/api/v1/accounts/{account}/featured_tags`, which belong to other
	 * controllers, and a route has to be offered to the matcher after the
	 * routes it can swallow. Attribute routes are contributed one controller at
	 * a time in whatever order the filesystem lists them, so it is declared in
	 * `appinfo/routes.php` instead, which the server loads after all of them.
	 * See the comment in that file.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	public function accountGet(string $id): DataResponse {
		try {
			$this->initViewer(false);
			$account = $this->resolveTargetAccount($id);
			$account->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($account, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The account behind a handle, without following it up remotely.
	 *
	 * Mastodon's `/accounts/lookup` is the cheap counterpart to `/search`: it
	 * answers with an account or a 404, never with a list, and never reaches
	 * out to another server. Clients use it to turn a `@user@host` someone
	 * typed or pasted into something they can open.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/lookup')]
	public function accountLookup(string $acct = ''): DataResponse {
		try {
			$this->initViewer(false);
			$acct = ltrim(trim($acct), '@');
			if ($acct === '') {
				throw new InvalidActionException('acct is required');
			}

			$account = $this->cacheActorService->getFromAccount($acct, false);
			$account->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($account, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The handle to judge an account's remoteness by.
	 *
	 * The route takes either a handle or a numeric id; only the handle carries
	 * the host, so for an id it comes from the account that was resolved.
	 */
	private function handleOf(string $reference, Person $actor): string {
		if (is_numeric($reference)) {
			return $actor->getAccount();
		}

		return $reference;
	}

	/**
	 * Resolve an account reference in any of the shapes a client can hold it:
	 * the numeric id every API entity emits, an `@user` or `user@host` handle,
	 * a bare local username, or the actor's ActivityPub id.
	 *
	 * The numeric id is the important one, because it is the only shape a
	 * client ever gets *from* this app. It used to fall through to
	 * `getFromId()`, which treats its argument as a URL: asking for account
	 * `42` meant a WebFinger lookup for the string "42", an exception, and
	 * (before the status codes were fixed) a 401 that logged the reader out.
	 *
	 * @throws CacheActorDoesNotExistException
	 * @throws Exception
	 */
	private function resolveTargetAccount(string $id): Person {
		$id = trim($id);

		if (is_numeric($id)) {
			if ((int)$id < 1) {
				throw new CacheActorDoesNotExistException('unknown account');
			}

			$actors = $this->cacheActorService->getFromNids([(int)$id]);
			if ($actors === []) {
				throw new CacheActorDoesNotExistException('unknown account');
			}

			return $actors[0];
		}

		if (str_starts_with($id, 'http://') || str_starts_with($id, 'https://')) {
			return $this->cacheActorService->getFromId($id);
		}

		if ($id === '') {
			throw new CacheActorDoesNotExistException('unknown account');
		}

		return $this->cacheActorService->getFromAccount(ltrim($id, '@'));
	}

	/**
	 * The viewer's relationship with each of the accounts asked about.
	 *
	 * `$id` carries its own default because a request-bound array parameter is
	 * filled in by the dispatcher, before the method's own try block: a client
	 * that asks with no `id[]` at all used to raise a TypeError there and get a
	 * Nextcloud error page instead of `{"error": …}`.
	 *
	 * @param array $id
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/relationships')]
	public function relationships(array $id = []): DataResponse {
		try {
			$this->initViewer(true);

			return new DataResponse($this->followService->getRelationships($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @param string $account
	 * @param int $limit
	 * @param int $max_id
	 * @param int $min_id
	 * @param int $since
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/{account}/statuses', requirements: ['account' => '.+'])]
	public function accountStatuses(
		string $account,
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since_id = 0,
		bool $pinned = false,
	): DataResponse {
		try {
			$this->initViewer(false);

			// `{account}` is whatever the client holds, which for every entity
			// this app emits is the numeric id — not the acct handle this route
			// used to insist on.
			$local = $this->resolveTargetAccount($account);

			if ($pinned) {
				return new DataResponse(
					$this->pinService->getPinnedPosts($local->getId(), $this->viewer),
					Http::STATUS_OK
				);
			}

			$this->streamService->syncRemoteTimeline($local);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::ACCOUNT)
				->setAccountId($local->getId())
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id);

			$posts = $this->streamService->getTimeline($options);
			$this->pinService->markPinned($posts, $local->getId());

			return $this->paged(
				$this->filterService->apply($posts, Filter::CONTEXT_ACCOUNT, $this->viewer),
				$options->getLimit(),
				$posts
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @param string $account
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/{account}/following', requirements: ['account' => '.+'])]
	public function accountFollowing(
		string $account,
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since = 0,
	): DataResponse {
		try {
			$this->initViewer(false);
			$actor = $this->resolveTargetAccount($account);

			$parts = explode('@', $this->handleOf($account, $actor));
			$domain = end($parts);
			$cloudHost = $this->configService->getCloudHost();
			$socialAddress = $this->configService->getSocialAddress();
			if ($domain !== '' && $domain !== $cloudHost && $domain !== $socialAddress) {
				$followingUrl = $actor->getFollowing();
				if (!empty($followingUrl)) {
					$result = $this->fetchRemoteCollection($followingUrl, $limit);
					return new DataResponse($result, Http::STATUS_OK);
				}
			}

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::FOLLOWING)
				->setAccountId($actor->getId())
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since);

			return $this->paged($this->cacheActorService->probeActors($options), $options->getLimit());
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @param string $account
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/{account}/followers', requirements: ['account' => '.+'])]
	public function accountFollowers(
		string $account,
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since = 0,
	): DataResponse {
		try {
			$this->initViewer(false);

			$actor = $this->resolveTargetAccount($account);

			$parts = explode('@', $this->handleOf($account, $actor));
			$domain = end($parts);
			$cloudHost = $this->configService->getCloudHost();
			$socialAddress = $this->configService->getSocialAddress();
			if ($domain !== '' && $domain !== $cloudHost && $domain !== $socialAddress) {
				$followersUrl = $actor->getFollowers();
				if (!empty($followersUrl)) {
					$result = $this->fetchRemoteCollection($followersUrl, $limit);
					return new DataResponse($result, Http::STATUS_OK);
				}
			}

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::FOLLOWERS)
				->setAccountId($actor->getId())
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since);

			return $this->paged($this->cacheActorService->probeActors($options), $options->getLimit());
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @param int $limit
	 * @param int $max_id
	 * @param int $min_id
	 * @param int $since_id
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/favourites/')]
	public function favourites(
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since_id = 0,
	): DataResponse {
		try {
			$this->initViewer(true);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::FAVOURITES)
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id);

			$posts = $this->streamService->getTimeline($options);

			return $this->paged(
				$this->filterService->apply($posts, '', $this->viewer), $options->getLimit(), $posts
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/bookmarks')]
	public function bookmarks(
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since_id = 0,
	): DataResponse {
		try {
			$this->initViewer(true);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::BOOKMARKS)
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id);

			$posts = $this->streamService->getTimeline($options);

			// not a filter context in Mastodon either: the statuses carry an
			// empty `filtered`, because its absence is read as an answer
			return $this->paged(
				$this->filterService->apply($posts, '', $this->viewer), $options->getLimit(), $posts
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * How many notifications have arrived since the reader last looked.
	 *
	 * The sidebar badge asks for this; a client that keeps markers gets the
	 * same answer from the same place.
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/notifications/unread_count')]
	public function notificationsUnreadCount(): DataResponse {
		try {
			$this->initViewer(true);
			$userId = $this->currentSession();

			return new DataResponse([
				'count' => $this->streamRequest->countNotificationsSince(
					$this->viewer, $this->markerService->lastReadId($userId, 'notifications')
				),
			], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * How far through each timeline the reader has got.
	 *
	 * @param array $timeline the timelines asked about; all of them when empty
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/markers')]
	public function markersGet(array $timeline = []): DataResponse {
		try {
			$this->initViewer(true);

			// an object, never a list: a fresh account has no markers at all,
			// and `[]` is not something a client can read `home.last_read_id`
			// out of
			return new DataResponse(
				(object)$this->markerService->get($this->currentSession(), $timeline),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Moves one or more markers forward.
	 *
	 * The body is Mastodon's: `{"notifications": {"last_read_id": "42"}}`, or
	 * the same thing form-encoded as `notifications[last_read_id]=42`.
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/markers')]
	public function markersSet(): DataResponse {
		try {
			$this->initViewer(true);
			$userId = $this->currentSession();

			$input = $this->convertInput(file_get_contents('php://input'));
			$updated = [];
			foreach (MarkerService::TIMELINES as $timeline) {
				$lastReadId = $input[$timeline]['last_read_id'] ?? null;
				if ($lastReadId === null || $lastReadId === '') {
					continue;
				}

				$updated[$timeline] = $this->markerService->set($userId, $timeline, (string)$lastReadId);
			}

			return new DataResponse((object)$updated, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/notifications')]
	public function notifications(
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since_id = 0,
		array $types = [],
		array $exclude_types = [],
		string $accountId = '',
	): DataResponse {
		try {
			$this->initViewer(true);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::NOTIFICATIONS)
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id)
				->setTypes($types)
				->setExcludeTypes($exclude_types)
				->setAccountId($accountId);

			// A notification whose sub-type Mastodon has no name for would
			// serialise as `"type": ""`, which a client with a closed enum
			// cannot decode — and one undecodable entry loses the whole page.
			// It is dropped instead: there is nothing a client could show for it.
			$page = $this->streamService->getTimeline($options);
			$posts = array_values(
				array_filter(
					$page,
					static fn (Stream $post): bool
						=> Stream::notificationTypeOfSubType($post->getSubType()) !== ''
				)
			);

			// paged() is told what the query returned, not what survived the
			// filter: a page shortened here says nothing about whether older
			// notifications exist, and a client that pages on the `Link` header
			// stopped there with the rest of the list still in the database.
			return $this->paged(
				$this->filterService->applyToNotifications($posts, $this->viewer),
				$options->getLimit(),
				$page
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/timelines/tag/{hashtag}')]
	public function tag(
		string $hashtag,
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since_id = 0,
		bool $local = false,
		bool $only_media = false,
		bool $only_video = false,
	): DataResponse {
		try {
			$this->initViewer(true);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe('hashtag')
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id)
				->setLocal($local)
				->setOnlyMedia($only_media)
				->setOnlyVideo($only_video)
				->setArgument($hashtag);

			$posts = $this->streamService->getTimeline($options);

			return $this->paged(
				$this->filterService->apply($posts, Filter::CONTEXT_PUBLIC, $this->viewer),
				$options->getLimit(),
				$posts
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * @param string $url
	 * @param int $limit
	 *
	 * @return array
	 */
	private function fetchRemoteCollection(string $url, int $limit = 20): array {
		// A remote page can carry far more entries than asked for, and each
		// unresolved id below is a remote fetch, so both the limit and the number of
		// entries walked are bounded — an anonymous caller must not be able to turn
		// one request into thousands of outbound fetches.
		$limit = max(1, min(ProbeOptions::MAX_LIMIT, $limit));

		try {
			$collectionData = $this->curlService->retrieveObject($url);
		} catch (Exception $e) {
			return [];
		}

		$pageData = $collectionData;
		if (isset($collectionData['first'])) {
			$pageUrl = is_array($collectionData['first'])
				? ($collectionData['first']['id'] ?? '')
				: $collectionData['first'];
			if (!empty($pageUrl) && is_string($pageUrl)) {
				try {
					$pageData = $this->curlService->retrieveObject($pageUrl);
				} catch (Exception $e) {
					return [];
				}
			} elseif (is_array($collectionData['first'])) {
				$pageData = $collectionData['first'];
			}
		}

		$items = $pageData['orderedItems'] ?? $pageData['items'] ?? [];
		if (!is_array($items)) {
			return [];
		}

		$actors = [];
		$count = 0;
		// array_slice bounds the walk itself: the $count guard alone only limits
		// successes, so a page of unresolvable ids would still be fetched one by one.
		foreach (array_slice($items, 0, $limit) as $item) {
			if ($count >= $limit) {
				break;
			}

			// An entry is either the actor's id or the actor inline; either way
			// it is resolved through the cache rather than built from the page.
			// An actor assembled straight from the remote JSON is not stored, so
			// it has no numeric id — and a page of accounts all sharing id "0"
			// is one a client cannot open, follow or mute, because every id in
			// the API addresses exactly one account.
			$actorId = '';
			if (is_array($item) && isset($item['id']) && is_string($item['id'])) {
				$actorId = $item['id'];
			} elseif (is_string($item)) {
				$actorId = $item;
			}

			if ($actorId === '') {
				continue;
			}

			try {
				$person = $this->cacheActorService->getFromId($actorId);
			} catch (Exception $e) {
				continue;
			}

			if ($person->getNid() <= 0) {
				continue;
			}

			$person->setExportFormat(ACore::FORMAT_LOCAL);
			$actors[] = $person;
			$count++;
		}

		return $actors;
	}

	/**
	 *
	 * @param bool $exception
	 *
	 * @return bool
	 * @throws ClientNotFoundException
	 */
	private function initViewer(bool $exception = false): bool {
		try {
			$userId = $this->currentSession();

			$this->logger->debug('[ApiController] initViewer: ' . $userId);

			// Get or create the actor
			$account = $this->accountService->getActorFromUserId($userId, true);
			$this->logger->debug('[ApiController] Actor retrieved/created', [
				'userId' => $userId,
				'username' => $account->getPreferredUsername()
			]);

			// Try to get from cache, if it fails, cache it first
			try {
				$this->viewer = $this->cacheActorService->getFromLocalAccount($account->getPreferredUsername());
			} catch (Exception $e) {
				$this->logger->warning('[ApiController] Actor not in cache, caching now', [
					'username' => $account->getPreferredUsername(),
					'exception' => $e->getMessage()
				]);
				// Cache the actor and retry
				$this->accountService->cacheLocalActorByUsername($account->getPreferredUsername());
				$this->viewer = $this->cacheActorService->getFromLocalAccount($account->getPreferredUsername());
			}

			$this->viewer->setExportFormat(ACore::FORMAT_LOCAL);

			$this->streamService->setViewer($this->viewer);
			$this->followService->setViewer($this->viewer);
			$this->cacheActorService->setViewer($this->viewer);

			$this->logger->info('[ApiController] Viewer initialized successfully', [
				'viewerId' => $this->viewer->getId()
			]);

			return true;
		} catch (InsufficientScopeException $e) {
			// the token is fine, its grant is not — tell the client which scope it lacks
			if ($exception) {
				throw $e;
			}
		} catch (Exception $e) {
			// A request with a missing, stale or made-up token is ordinary
			// internet noise — every scanner that finds the API produces some —
			// and it is answered with a 401, not a server-side failure. Logging
			// each one at error with a stack trace filled the admin's log with
			// entries nobody can act on. Anything else failing here is a real
			// fault and still says so.
			$credentials = ($e instanceof ClientNotFoundException
				|| $e instanceof AccountDoesNotExistException
				|| $e instanceof ActorDoesNotExistException);
			if ($credentials) {
				$this->logger->debug('[ApiController] initViewer: no usable credentials', [
					'exception' => $e->getMessage()
				]);
			} else {
				$this->logger->warning('[ApiController] initViewer failed', ['exception' => $e]);
			}

			if ($exception) {
				throw new ClientNotFoundException('the access_token was revoked');
			}
		}

		return false;
	}

	/**
	 * The parameters of a request that carries its body itself.
	 *
	 * A body that says it is JSON and is not one is refused: `json_decode()`
	 * answers `null` for an empty, truncated or scalar body, which under
	 * strict_types raised a TypeError out of a method declared `: array` — a
	 * Nextcloud HTML error page, stack trace and all, for every client that
	 * lost a byte on the way.
	 *
	 * @throws InvalidActionException
	 */
	private function convertInput(string $input): array {
		$contentType = $this->request->getHeader('Content-Type');

		$pos = strpos($contentType, ';');
		if ($pos > 0) {
			$contentType = substr($contentType, 0, $pos);
		}

		switch ($contentType) {
			case 'application/json':
				$result = json_decode($input, true);
				if (!is_array($result)) {
					throw new InvalidActionException('the request body is not valid JSON');
				}

				return $result;
			case 'application/x-www-form-urlencoded':
				return $this->request->getParams();
			default: // in case of no header ...
				$result = json_decode($input, true);
				if (is_array($result)) {
					return $result;
				}

				return $this->request->getParams();
		}
	}

	/**
	 * @return string
	 * @throws AccountDoesNotExistException
	 * @throws ClientNotFoundException
	 */
	/**
	 * A bearer token wins over the session cookie: an OAuth client stays inside
	 * the scopes it was granted even when the browser also carries a session.
	 * The cookie is only accepted together with a valid CSRF token — these
	 * routes carry #[NoCSRFRequired] so that external clients (which cannot obtain
	 * one) work, and without this check a cross-site form POST would act as the
	 * logged-in user.
	 */
	private function currentSession(): string {
		if ($this->bearer !== '') {
			$this->client = $this->clientService->getFromToken($this->bearer);
			$this->checkTokenScope();

			return $this->client->getAuthUserId();
		}

		$user = $this->userSession->getUser();
		if ($user !== null && $this->request->passesCSRFCheck()) {
			return $user->getUID();
		}

		throw new AccountDoesNotExistException('userId not defined');
	}

	/**
	 * The scope a bearer token needs for the current route. Everything defaults
	 * to 'read'; the state-changing routes are enumerated. A scope is satisfied
	 * by itself or any of its granular variants ('write' by 'write:statuses').
	 *
	 * @throws ClientNotFoundException
	 */
	private function checkTokenScope(): void {
		$route = $this->request->getParam('_route', '');
		$name = substr((string)$route, strrpos((string)$route, '.') + 1);

		$accepted = match ($name) {
			'statusNew', 'statusUpdate', 'statusDelete', 'mediaNew', 'mediaNewV2', 'mediaUpdate',
			'statusAction', 'updateCredentials', 'reportNew', 'pollVote', 'markersSet',
			'scheduledStatusUpdate', 'scheduledStatusDelete' => ['write'],
			'accountBlock', 'accountUnblock', 'accountMute', 'accountUnmute',
			'accountFollow', 'accountUnfollow',
			'followRequestAuthorize', 'followRequestReject' => ['follow', 'write'],
			'appsCredentials' => [],
			default => ['read'],
		};

		foreach ($accepted as $scope) {
			foreach ($this->client->getAuthScopes() as $granted) {
				if ($granted === $scope || str_starts_with($granted, $scope . ':')) {
					return;
				}
			}
		}

		if ($accepted !== []) {
			throw new InsufficientScopeException(
				'token scope does not allow this request (needs ' . implode(' or ', $accepted) . ')'
			);
		}
	}

	/**
	 * The HTTP status each failure maps to, in the order the classes are
	 * tested. Everything used to answer 401, which a client reads as "this
	 * token is gone": a deleted status, a mistyped timeline name, a database
	 * hiccup and a slow remote all logged the reader out of their client and
	 * left nothing to diagnose. `InsufficientScopeException` is handled ahead
	 * of this list because it extends `ClientException`.
	 */
	private const ERROR_STATUS = [
		// gone, or never existed
		[StreamNotFoundException::class, Http::STATUS_NOT_FOUND],
		[CacheActorDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[ActorDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[ItemNotFoundException::class, Http::STATUS_NOT_FOUND],
		[CacheDocumentDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[HashtagDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[ReportNotFoundException::class, Http::STATUS_NOT_FOUND],
		[FollowNotFoundException::class, Http::STATUS_NOT_FOUND],
		[InstanceDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[NotFoundException::class, Http::STATUS_NOT_FOUND],
		// the request was understood and refused: retrying it unchanged cannot help
		[InvalidActionException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[UnknownProbeException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[InvalidResourceException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[InvalidResourceEntryException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[InvalidHandleException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[ItemUnknownException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[CacheContentMimeTypeException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[ClientException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		// the credentials, not the request
		[ClientNotFoundException::class, Http::STATUS_UNAUTHORIZED],
		[AccountDoesNotExistException::class, Http::STATUS_UNAUTHORIZED],
		// allowed to ask, not allowed to have
		[UnauthorizedFediverseException::class, Http::STATUS_FORBIDDEN],
		[TooManyRequestsException::class, Http::STATUS_TOO_MANY_REQUESTS],
		// somebody else's server let us down
		[RequestContentException::class, Http::STATUS_NOT_FOUND],
		[RequestNetworkException::class, Http::STATUS_BAD_GATEWAY],
		[RequestServerException::class, Http::STATUS_BAD_GATEWAY],
		[RequestResultNotJsonException::class, Http::STATUS_BAD_GATEWAY],
		[RequestResultSizeException::class, Http::STATUS_BAD_GATEWAY],
	];

	/**
	 * A failure as a Mastodon client can act on it: `{"error": "..."}` with a
	 * status that says what to do about it.
	 *
	 * An unrecognised failure is a bug on this side, so it answers 500 and is
	 * logged here with its stack trace — and its message is *not* sent on.
	 * Every route in this controller is a `#[PublicPage]`, so echoing
	 * `getMessage()` published whatever the failure happened to name: a table,
	 * a file path, an internal host.
	 */
	private function error(Throwable $e): DataResponse {
		if ($e instanceof InsufficientScopeException) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_FORBIDDEN,
				['WWW-Authenticate' => 'Bearer error="insufficient_scope"']
			);
		}

		foreach (self::ERROR_STATUS as [$class, $status]) {
			if ($e instanceof $class) {
				$headers = ($status === Http::STATUS_UNAUTHORIZED)
					? ['WWW-Authenticate' => 'Bearer error="invalid_token"'] : [];

				return new DataResponse(
					['error' => $this->errorMessage($e, $status)], $status, $headers
				);
			}
		}

		$this->logger->error('[ApiController] unexpected failure answering the client API', [
			'exception' => $e,
			'route' => (string)$this->request->getParam('_route', ''),
		]);

		return new DataResponse(
			['error' => 'internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}

	/**
	 * What to put in `error`. Several of these failures are raised with no
	 * message at all — an unknown token is one — and `{"error": ""}` tells a
	 * client nothing about what to do next.
	 */
	private function errorMessage(Throwable $e, int $status): string {
		$message = trim($e->getMessage());
		if ($message !== '') {
			return $message;
		}

		return match ($status) {
			Http::STATUS_UNAUTHORIZED => 'the access_token is invalid',
			Http::STATUS_NOT_FOUND => 'not found',
			Http::STATUS_UNPROCESSABLE_ENTITY => 'the request could not be processed',
			default => 'request failed',
		};
	}

	/**
	 * A page of entities, with the `Link` header Mastodon pages with.
	 *
	 * masto.js — which Elk and Phanpy are both built on — takes the next page
	 * from this header and nowhere else, so without it those clients show the
	 * first twenty posts of a timeline and stop. Mastodon sends `next` only
	 * while a further page may exist (a short page is the last one) and `prev`
	 * whenever the page is not empty.
	 *
	 * @param array|null $page the rows the query returned, where `$items` is a
	 *                         filtered subset of them
	 */
	/**
	 * The filter context a timeline is read in. Favourites, bookmarks and the
	 * direct timeline are not contexts Mastodon filters in: their statuses
	 * still carry `filtered`, empty, because its absence is read as an answer.
	 */
	private function filterContext(string $timeline): string {
		return match (strtolower($timeline)) {
			ProbeOptions::HOME => Filter::CONTEXT_HOME,
			ProbeOptions::PUBLIC => Filter::CONTEXT_PUBLIC,
			default => '',
		};
	}

	private function paged(array $items, int $limit, ?array $page = null): DataResponse {
		$response = new DataResponse($items, Http::STATUS_OK);

		// what the query returned, which is what says whether there is more —
		// $items may have been filtered since
		$page ??= $items;

		$ids = $this->pageIds($page);
		if ($ids === []) {
			return $response;
		}

		$links = [];
		if (count($page) >= $limit) {
			// the next page is older than everything here
			$links[] = '<' . $this->pageUrl(['max_id' => (string)min($ids)]) . '>; rel="next"';
		}
		$links[] = '<' . $this->pageUrl(['min_id' => (string)max($ids)]) . '>; rel="prev"';

		$response->addHeader('Link', implode(', ', $links));

		return $response;
	}

	/**
	 * The paging ids of a page of entities. Streams and actors both carry the
	 * numeric id the API pages by; an already-serialised entity carries it as
	 * its string `id`.
	 *
	 * @return int[]
	 */
	private function pageIds(array $items): array {
		$ids = [];
		foreach ($items as $item) {
			$nid = 0;
			if (is_object($item) && method_exists($item, 'getNid')) {
				$nid = (int)$item->getNid();
			} elseif (is_array($item)) {
				$nid = (int)($item['id'] ?? 0);
			}

			if ($nid > 0) {
				$ids[] = $nid;
			}
		}

		return $ids;
	}

	/**
	 * This request's own URL with the cursor replaced, so every other filter
	 * the client sent (`limit`, `types`, `only_media`, …) survives into the
	 * next page. Built with http_build_query rather than string concatenation:
	 * a route that already carries a query string must not end up with two
	 * `?`s in it.
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

		return $this->urlGenerator->getAbsoluteURL($path) . '?'
			. http_build_query(array_merge($query, $cursor));
	}
}
