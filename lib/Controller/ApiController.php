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
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\Post;
use OCA\Social\Model\Report;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActionService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\MarkerService;
use OCA\Social\Service\PinService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\RelationshipService;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Traits\TNCDataResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
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
	public function verifyCredentials() {
		try {
			$this->initViewer(true);

			return new DataResponse($this->accountEntity($this->viewer), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Minimal Mastodon-style profile update: `locked` (manually approve
	 * followers), `note` (the bio), `discoverable` and `indexable` (the actor
	 * flags) and `fields_attributes` (profile metadata) are supported.
	 * `display_name` is not: the name belongs to the Nextcloud account and is
	 * changed there. Returns the updated account entity.
	 *
	 * Every field is optional and only what was sent is written, which is what
	 * lets a client that edits one thing leave the rest alone.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
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

			// only the flags that were sent: a client updating the display
			// name must not reset the ones it did not mention
			$flags = [];
			foreach (['discoverable', 'indexable'] as $flag) {
				if (array_key_exists($flag, $input)) {
					$flags[$flag] = $this->formBool($input[$flag]);
				}
			}
			if ($flags !== []) {
				$this->accountService->setActorFlags($this->currentSession(), $flags);
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
	private function accountEntity(Person $account): array {
		// the viewer is already in local format, see initViewer()
		$data = $account->jsonSerialize();

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
	public function followRequestAuthorize(string $id): DataResponse {
		return $this->followRequestAction($id, true);
	}

	#[NoCSRFRequired]
	#[PublicPage]
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
	 * Votes on a federated poll: the choices go to the poll's author as
	 * ActivityPub vote notes, the authoritative counts come back later as an
	 * Update from the origin server.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
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
				(string)($input['category'] ?? Report::CATEGORY_OTHER)
			);
			$target->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($report, Http::STATUS_OK);
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
	public function customEmojis(): DataResponse {
		return new DataResponse([], Http::STATUS_OK);
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
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
	public function instance(): DataResponse {
		$local = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);

		return new DataResponse($local, Http::STATUS_OK);
	}

	/**
	 * Mastodon's V2::Instance entity. Newer clients ask for this one first and
	 * fall back to v1 on a 404; answering it saves them the round trip.
	 *
	 * @throws InstanceDoesNotExistException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
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
	public function statusNew(): DataResponse {
		try {
			$this->initViewer(true);

			$input = file_get_contents('php://input');
			$this->logger->debug('[ApiController] statusNew: ' . $input);

			$status = new Status();
			$status->import($this->convertInput($input));

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
	 * with"; Mastodon resolves that against the account's default privacy,
	 * which this app has no setting for, so `public` stands in — the value a
	 * status route is asked for by every bot and minimal client that omits it.
	 * Anything this app does not know is refused rather than posted:
	 * `Stream::visibilityFromClient()` maps an unknown value to `direct`, and a
	 * direct message gets no recipient added, so those posts used to answer 200
	 * and be delivered to nobody.
	 *
	 * @throws InvalidActionException
	 */
	private function visibilityOf(Status $status): string {
		$visibility = trim($status->getVisibility());
		if ($visibility === '') {
			return Stream::TYPE_PUBLIC;
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
				$this->storeAttachment($name, (string)$this->request->getParam('description', '')),
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
	private function storeAttachment(string $tmpPath, string $description): MediaAttachment {
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
		// the alt text; `focus` is accepted but not stored (no focal-point support)
		$document->setDescription($description);

		$this->cacheDocumentService->saveFromTempToCache($document, $tmpPath);
		$service = AP::$activityPub->getInterfaceForItem($document);
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
	public function mediaNewV2(): DataResponse {
		return $this->mediaNew();
	}

	/**
	 * One of the viewer's own attachments, by the id mediaNew returned.
	 *
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function mediaGet(string $nid, string $preview = ''): Response {
		try {
			$this->initViewer(true);

			return new DataResponse($this->ownAttachment($nid), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Updates the alt text of the viewer's own attachment.
	 *
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function mediaUpdate(string $nid): Response {
		try {
			$this->initViewer(true);

			$document = $this->ownDocument($nid);
			$input = $this->convertInput(file_get_contents('php://input'));
			if (array_key_exists('description', $input)) {
				$document->setDescription((string)$input['description']);
				$this->documentService->updateDescription($document);
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
	public function timelines(
		string $timeline,
		bool $local = false,
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since_id = 0,
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
				->setSince($since_id);

			$posts = $this->streamService->getTimeline($options);
			$this->logger->info('[ApiController] Timeline retrieved', [
				'timeline' => $timeline,
				'postsCount' => count($posts)
			]);

			return $this->paged($posts, $options->getLimit());
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
	 *
	 * @param int $nid
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function statusContext(int $nid): DataResponse {
		try {
			$this->initViewer(false);
			$context = $this->streamService->getContextByNid($nid);

			return new DataResponse($context, Http::STATUS_OK);
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
	 * Follows the account, or asks to (a locked account leaves the
	 * relationship in `requested`). Returns the updated relationship.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function accountFollow(string $id): DataResponse {
		try {
			$this->initViewer(true);
			$target = $this->resolveTargetAccount($id);

			$this->followService->followAccount($this->viewer, $target->getAccount());
			$this->accountService->cacheLocalActorDetailCount($this->viewer);

			return new DataResponse(
				$this->followService->getRelationshipWith($target), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
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
	public function accountBlock(string $id): DataResponse {
		return $this->relationshipAction($id, 'block');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function accountUnblock(string $id): DataResponse {
		return $this->relationshipAction($id, 'unblock');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function accountMute(string $id, bool $notifications = true): DataResponse {
		return $this->relationshipAction($id, 'mute', $notifications);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function accountUnmute(string $id): DataResponse {
		return $this->relationshipAction($id, 'unmute');
	}

	private function relationshipAction(string $id, string $action, bool $notifications = true): DataResponse {
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
					break;
				case 'unmute':
					$this->relationshipService->unmute($this->viewer, $target);
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
	public function blocks(int $limit = 40): DataResponse {
		return $this->listRelatedAccounts(ActorRelation::TYPE_BLOCK, $limit);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function mutes(int $limit = 40): DataResponse {
		return $this->listRelatedAccounts(ActorRelation::TYPE_MUTE, $limit);
	}

	private function listRelatedAccounts(string $type, int $limit): DataResponse {
		try {
			$this->initViewer(true);
			$limit = max(1, min(ProbeOptions::MAX_LIMIT, $limit));

			$accounts = [];
			foreach ($this->relationshipService->getRelated($this->viewer, $type, $limit) as $person) {
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

			return $this->paged($posts, $options->getLimit());
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

			return $this->paged($posts, $options->getLimit());
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
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

			return $this->paged($posts, $options->getLimit());
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
			return $this->paged($posts, $options->getLimit(), $page);
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
	public function tag(
		string $hashtag,
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since_id = 0,
		bool $local = false,
		bool $only_media = false,
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
				->setArgument($hashtag);

			$posts = $this->streamService->getTimeline($options);

			return $this->paged($posts, $options->getLimit());
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
			'statusAction', 'updateCredentials', 'reportNew', 'pollVote', 'markersSet' => ['write'],
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
