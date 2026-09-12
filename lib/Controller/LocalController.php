<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Post;
use OCA\Social\Security\RemoteAddress;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\BannerService;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\LikeService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TNCDataResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Class LocalController
 *
 * @package OCA\Social\Controller
 */
class LocalController extends Controller {
	/** Ceiling for a banner fetched by URL. */
	private const BANNER_MAX_SIZE = 10 * 1024 * 1024;

	use TArrayTools;
	use TNCDataResponse;

	private ?string $userId = null;
	private ?Person $viewer = null;

	public function __construct(
		IRequest $request,
		?string $userId,
		private AccountService $accountService,
		private CacheActorService $cacheActorService,
		private CacheActorsRequest $cacheActorsRequest,
		private HashtagService $hashtagService,
		private FollowService $followService,
		private PostService $postService,
		private StreamService $streamService,
		private SearchService $searchService,
		private BoostService $boostService,
		private LikeService $likeService,
		private DocumentService $documentService,
		private MiscService $miscService,
		private ConfigService $configService,
		private LoggerInterface $logger,
		private ActorService $actorService,
		private ActivityService $activityService,
		private CacheDocumentService $cacheDocumentService,
		private BannerService $bannerService,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->userId = $userId;
	}

	/**
	 * Upload a banner/header image for the current user's profile.
	 *
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/banner')]
	public function uploadBanner(): DataResponse {
		try {
			if ($this->userId === null) {
				throw new AccountDoesNotExistException('User not logged in');
			}

			$file = $_FILES['file'] ?? [];
			if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
				throw new Exception('no banner file provided');
			}

			$tmpName = $file['tmp_name'];

			$image = $this->bannerService->setFromTempFile($this->userId, $tmpName);

			$this->logger->info('[LocalController] Banner uploaded', [
				'userId' => $this->userId,
				'url' => $image->getUrl()
			]);

			return $this->success([
				'url' => $image->getUrl(),
				'id' => $image->getId()
			]);
		} catch (Exception $e) {
			$this->logger->error('[LocalController] uploadBanner failed', [
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			return $this->fail($e);
		}
	}

	/**
	 *
	 * @param string $url
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/banner/url')]
	public function uploadBannerByUrl(string $url = ''): DataResponse {
		$tmpFile = null;
		try {
			if ($this->userId === null) {
				throw new AccountDoesNotExistException('User not logged in');
			}
			if ($url === '') {
				throw new Exception('No URL provided');
			}

			// A user hands us this URL, so it must not become a way to read the
			// server's own network. Only http(s) to a non-local host, no local
			// addresses unless the admin opted in, and a hard size ceiling.
			$parsed = parse_url($url);
			$scheme = strtolower($parsed['scheme'] ?? '');
			$host = $parsed['host'] ?? '';
			if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
				throw new Exception('Unsupported banner URL');
			}
			$allowLocal = $this->configService->isLocalNetworkAllowed();
			if (!$allowLocal && RemoteAddress::isLocalHost($host)) {
				throw new Exception('Unsupported banner URL');
			}

			$this->logger->info('[LocalController] Banner upload by URL', [
				'userId' => $this->userId,
				'host' => $host,
			]);

			// The download goes through the app's own HTTP client rather than a
			// hand-rolled curl handle. That is what makes the checks above hold:
			// the client re-checks the target on every redirect it follows and
			// pins the resolved address, so a 302 to 127.0.0.1 or to a cloud
			// metadata endpoint is refused instead of fetched. It also applies
			// the instance access list and the configured size ceiling.
			$content = $this->cacheDocumentService->retrieveContent($url);
			if (strlen($content) > self::BANNER_MAX_SIZE) {
				throw new Exception('Banner image is too large');
			}

			$tmpFile = tempnam(sys_get_temp_dir(), 'social_banner_');
			if ($tmpFile === false || file_put_contents($tmpFile, $content) === false) {
				throw new Exception('Cannot store the downloaded banner');
			}

			$image = $this->bannerService->setFromTempFile($this->userId, $tmpFile);

			$this->logger->info('[LocalController] Banner uploaded via URL', [
				'userId' => $this->userId,
				'url' => $image->getUrl(),
			]);

			return $this->success([
				'url' => $image->getUrl(),
				'id' => $image->getId(),
			]);
		} catch (Exception $e) {
			$this->logger->error('[LocalController] uploadBannerByUrl failed', [
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);
			return $this->fail($e);
		} finally {
			if (is_string($tmpFile) && $tmpFile !== '' && file_exists($tmpFile)) {
				unlink($tmpFile);
			}
		}
	}

	/**
	 * Create a new post.
	 *
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/post')]
	public function postCreate(string $content = '', array $to = [], ?string $type = null, ?string $replyTo = null, $attachments = null, array $hashtags = [], ?array $poll = null, string $spoilerText = ''): DataResponse {
		$content = $content ?: '';
		$replyTo = $replyTo ?? '';
		$type = $type ?? Stream::TYPE_PUBLIC;
		$attachments = $attachments ?? [];

		$this->logger->info('[LocalController] postCreate called', [
			'userId' => $this->userId,
			'contentLength' => strlen($content),
			'type' => $type,
			'hasAttachments' => !empty($attachments),
		]);

		try {
			if ($this->userId === null) {
				$this->logger->error('[LocalController] postCreate: User not logged in');
				throw new AccountDoesNotExistException('User not logged in');
			}
			$actor = $this->accountService->getActorFromUserId($this->userId);
			$this->logger->debug('[LocalController] Actor retrieved', ['actorId' => $actor->getId()]);

			$post = new Post($actor);
			$post->setContent($content);
			$post->setReplyTo($replyTo);
			$post->setTo($to);
			$post->setType($type);
			$post->setHashtags($hashtags);
			$post->setAttachments($attachments);
			$post->setPoll($poll);
			$post->setSpoilerText($spoilerText);

			$token = '';
			$activity = $this->postService->createPost($post, $token);
			$this->logger->info('[LocalController] Post created successfully', [
				'token' => $token,
				'activityId' => $activity->getId()
			]);

			return $this->success(
				[
					'post' => $activity->getObject(),
					'token' => $token
				]
			);
		} catch (InvalidActionException $e) {
			// The request was understood and refused: too long, or a quote of a
			// post that may not be quoted. `fail()` answers 500 with 'request
			// failed', which reads as "the server broke" and leaves the composer
			// nothing to say; this one exception is raised with a message meant
			// for whoever is writing the post, so it is the one that is passed on.
			return new DataResponse(
				['status' => -1, 'error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (Exception $e) {
			$this->logger->error('[LocalController] postCreate failed', [
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			return $this->fail($e);
		}
	}

	/**
	 * Get info about a post (limited to viewer rights).
	 *
	 */
	#[NoAdminRequired]
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/local/v1/post')]
	public function postGet(string $id): DataResponse {
		$this->logger->debug('[LocalController] postGet called', ['id' => $id]);
		try {
			$this->initViewer(false);
			$stream = $this->streamService->getStreamById($id, true);
			$this->logger->info('[LocalController] Post retrieved', [
				'id' => $id,
				'streamId' => $stream->getId()
			]);

			return $this->directSuccess($stream);
		} catch (Exception $e) {
			$this->logger->error('[LocalController] postGet failed', [
				'id' => $id,
				'exception' => $e->getMessage()
			]);
			return $this->fail($e);
		}
	}

	/**
	 * Get replies about a post (limited to viewer rights).
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/local/v1/post/replies')]
	public function postReplies(string $id, int $since = 0, int $limit = 5): DataResponse {
		try {
			$this->initViewer(true);

			return $this->success($this->streamService->getRepliesByParentId($id, $since, $limit, true));
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * Delete your own post.
	 *
	 *
	 * @param string $id
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/post')]
	public function postDelete(string $id): DataResponse {
		try {
			if ($this->userId === null) {
				throw new AccountDoesNotExistException('User not logged in');
			}
			$note = $this->streamService->getStreamById($id);
			$actor = $this->accountService->getActorFromUserId($this->userId);
			if ($note->getAttributedTo() !== $actor->getId()) {
				throw new InvalidResourceException('user have no rights');
			}

			$this->streamService->deleteLocalItem($note, Note::TYPE);

			return $this->success();
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * Like a post.
	 *
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/post/like')]
	public function postLike(string $postId): DataResponse {
		try {
			$this->initViewer(true);
			$token = '';
			$announce = $this->likeService->create($this->viewer, $postId, $token);

			return $this->success(
				[
					'like' => $announce,
					'token' => $token
				]
			);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * Unlike a post.
	 *
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/post/like')]
	public function postUnlike(string $postId): DataResponse {
		try {
			$this->initViewer(true);
			$token = '';
			$like = $this->likeService->delete($this->viewer, $postId, $token);

			return $this->success(
				[
					'like' => $like,
					'token' => $token
				]
			);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/stream/home')]
	public function streamHome(int $since = 0, int $limit = 5): DataResponse {
		$this->logger->debug('[LocalController] streamHome called', [
			'since' => $since,
			'limit' => $limit,
			'userId' => $this->userId
		]);
		try {
			$this->initViewer(true);
			$posts = $this->streamService->getStreamHome($since, $limit);
			$this->logger->info('[LocalController] streamHome returned', [
				'postsCount' => count($posts)
			]);

			return $this->success($posts);
		} catch (Exception $e) {
			$this->logger->error('[LocalController] streamHome failed', [
				'exception' => $e->getMessage()
			]);
			return $this->fail($e);
		}
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/stream/notifications')]
	public function streamNotifications(int $since = 0, int $limit = 5): DataResponse {
		try {
			$this->initViewer(true);
			$posts = $this->streamService->getStreamNotifications($since, $limit);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * A profile timeline, which first asks the account's own server for its outbox.
	 *
	 * Anyone may call this and the handle names the server that is fetched, so the
	 * throttle has to be real: without it one anonymous request per second keeps a
	 * worker busy fetching from, and ingesting into, whatever host the caller picked.
	 */
	#[NoAdminRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/account/{username}/stream', requirements: ['username' => '.+'])]
	public function streamAccount(string $username, int $since = 0, int $limit = 5): DataResponse {
		try {
			$this->initViewer();

			$account = $this->cacheActorService->getFromAccount($username);
			// Best-effort: a slow or unreachable remote must not fail the profile
			// view — it falls back to whatever is already cached.
			try {
				$this->streamService->syncRemoteTimeline($account);
			} catch (\Exception $e) {
				$this->logger->debug('[LocalController] outbox sync skipped', ['exception' => $e]);
			}
			$posts = $this->streamService->getStreamAccount($account->getId(), $since, $limit);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/stream/direct')]
	public function streamDirect(int $since = 0, int $limit = 5): DataResponse {
		try {
			$this->initViewer(true);
			$posts = $this->streamService->getStreamDirect($since, $limit);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * Get timeline
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/stream/timeline')]
	public function streamTimeline(int $since = 0, int $limit = 5): DataResponse {
		$this->logger->debug('[LocalController] streamTimeline called', [
			'since' => $since,
			'limit' => $limit,
			'userId' => $this->userId
		]);
		try {
			$this->initViewer(true);
			$posts = $this->streamService->getStreamLocalTimeline($since, $limit);
			$this->logger->info('[LocalController] streamTimeline returned', [
				'postsCount' => count($posts)
			]);

			return $this->success($posts);
		} catch (Exception $e) {
			$this->logger->error('[LocalController] streamTimeline failed', [
				'exception' => $e->getMessage()
			]);
			return $this->fail($e);
		}
	}

	/**
	 * Get timeline
	 *
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/stream/tag/{hashtag}/')]
	public function streamTag(string $hashtag, int $since = 0, int $limit = 5): DataResponse {
		try {
			$this->initViewer(true);
			$posts = $this->streamService-> getStreamLocalTag($hashtag, $since, $limit);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * Get timeline
	 *
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/stream/federated')]
	public function streamFederated(int $since = 0, int $limit = 5): DataResponse {
		try {
			$this->initViewer(true);
			$posts = $this->streamService->getStreamGlobalTimeline($since, $limit);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * Get liked post
	 *
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/stream/liked')]
	public function streamLiked(int $since = 0, int $limit = 5): DataResponse {
		try {
			$this->initViewer(true);
			$posts = $this->streamService->getStreamLiked($since, $limit);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/current/follow')]
	public function actionFollow(string $account): DataResponse {
		try {
			if ($this->userId === null) {
				throw new AccountDoesNotExistException('User not logged in');
			}
			$actor = $this->accountService->getActorFromUserId($this->userId);
			$this->followService->followAccount($actor, $account);
			$this->accountService->cacheLocalActorDetailCount($actor);

			return $this->success([]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/current/follow')]
	public function actionUnfollow(string $account): DataResponse {
		try {
			if ($this->userId === null) {
				throw new AccountDoesNotExistException('User not logged in');
			}
			$actor = $this->accountService->getActorFromUserId($this->userId);
			$this->followService->unfollowAccount($actor, $account);
			$this->accountService->cacheLocalActorDetailCount($actor);

			return $this->success([]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/current/info')]
	public function currentInfo(): DataResponse {
		try {
			if ($this->userId === null) {
				throw new AccountDoesNotExistException('User not logged in');
			}
			$local = $this->accountService->getActorFromUserId($this->userId);
			$this->accountService->cacheLocalActorByUsername($local->getPreferredUsername());
			$actor = $this->cacheActorService->getFromLocalAccount($local->getPreferredUsername());

			return $this->success(['account' => $actor]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * Replace the current user's profile metadata fields (the name/value
	 * table under the bio, at most four entries).
	 *
	 * @param array $fields [['name' => string, 'value' => string], …]
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/account/fields')]
	public function accountFields(array $fields = []): DataResponse {
		try {
			if ($this->userId === null) {
				throw new AccountDoesNotExistException('User not logged in');
			}
			$this->accountService->setFields($this->userId, $fields);

			$local = $this->accountService->getActorFromUserId($this->userId);
			$actor = $this->cacheActorService->getFromLocalAccount($local->getPreferredUsername());

			return $this->success(['account' => $actor]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * Replace the current user's bio — the text under the display name.
	 *
	 * Plain text, at most 500 characters; `AccountService::setSummary()`
	 * flattens any markup and cuts what is over the limit, then federates the
	 * change to the followers.
	 *
	 * @param string $summary the bio as plain text
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/account/summary')]
	public function accountSummary(string $summary = ''): DataResponse {
		try {
			if ($this->userId === null) {
				throw new AccountDoesNotExistException('User not logged in');
			}
			$this->accountService->setSummary($this->userId, $summary);

			$local = $this->accountService->getActorFromUserId($this->userId);
			$actor = $this->cacheActorService->getFromLocalAccount($local->getPreferredUsername());

			return $this->success(['account' => $actor]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/current/followers')]
	public function currentFollowers(): DataResponse {
		try {
			if ($this->userId === null) {
				throw new AccountDoesNotExistException('User not logged in');
			}
			$this->initViewer();

			$actor = $this->accountService->getActorFromUserId($this->userId);
			$followers = $this->followService->getFollowers($actor);

			return $this->success($followers);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/current/following')]
	public function currentFollowing(): DataResponse {
		try {
			if ($this->userId === null) {
				throw new AccountDoesNotExistException('User not logged in');
			}
			$this->initViewer();

			$actor = $this->accountService->getActorFromUserId($this->userId);
			$following = $this->followService->getFollowing($actor);

			return $this->success($following);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	#[NoAdminRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/account/{username}/info')]
	public function accountInfo(string $username): DataResponse {
		try {
			$this->initViewer();

			$actor = $this->getLocalAccountWithCacheFallback($username);
			$actor->setCompleteDetails(true);
			$actor->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($actor, Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * Everything known about one account, resolving an unknown handle remotely.
	 *
	 * A handle this instance has never seen costs a host-meta, a WebFinger and four
	 * signed actor fetches — six outbound requests at a ten-second timeout, aimed at
	 * a host the caller names and signed by a named local actor. `OStatusController::getLink`
	 * throttles a single WebFinger lookup for the same reason.
	 */
	#[NoAdminRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 10, period: 300)]
	#[UserRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/global/account/info')]
	public function globalAccountInfo(string $account): DataResponse {
		$this->logger->debug('[LocalController] globalAccountInfo called', ['account' => $account]);
		try {
			$this->initViewer();

			// Check if this is a local account and ensure actor exists
			$account = ltrim($account, '@');
			$parts = explode('@', $account, 2);
			$username = $parts[0];
			$domain = $parts[1] ?? '';

			// If this is a local account, ensure the actor is created
			$isLocal = $domain === '';
			if (!$isLocal) {
				try {
					$cloudHost = $this->configService->getCloudHost();
					$socialAddress = $this->configService->getSocialAddress();
					$isLocal = ($domain === $cloudHost || $domain === $socialAddress);
				} catch (Exception $e) {
					$this->logger->debug('[LocalController] Could not get cloud config', ['exception' => $e->getMessage()]);
				}
			}

			if ($isLocal && $this->userId === $username) {
				$this->logger->debug('[LocalController] Local account detected', ['username' => $username]);
				try {
					// Only the user themself triggers actor creation. This route is
					// public: creating on any request would let anonymous visitors
					// force a Fediverse identity (RSA key pair and all) onto every
					// Nextcloud user, and confirm which usernames exist.
					$this->accountService->getActorFromUserId($username, true);
					$this->accountService->cacheLocalActorByUsername($username);
					$this->logger->info('[LocalController] Local actor ensured', ['username' => $username]);
				} catch (Exception $e) {
					$this->logger->warning('[LocalController] Failed to ensure local actor', [
						'username' => $username,
						'exception' => $e->getMessage()
					]);
				}
			}

			if ($isLocal) {
				$actor = $this->getLocalAccountWithCacheFallback($username);
			} else {
				$actor = $this->cacheActorService->getFromAccount($account);
			}
			$actor->setExportFormat(ACore::FORMAT_LOCAL);

			// For remote actors, fetch follower/following/post counts
			if (!$actor->isLocal()) {
				try {
					$this->cacheActorService->addRemoteActorDetailCount($actor);
				} catch (Exception $e) {
					$this->logger->debug('[LocalController] Failed to fetch remote actor details', [
						'account' => $account,
						'error' => $e->getMessage()
					]);
				}
			}

			$this->logger->info('[LocalController] Actor info retrieved', ['actorId' => $actor->getId()]);
			return new DataResponse($actor, Http::STATUS_OK);
		} catch (Exception $e) {
			$this->logger->error('[LocalController] globalAccountInfo failed', [
				'account' => $account,
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			return $this->fail($e);
		}
	}

	#[NoAdminRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/global/actor/info')]
	public function globalActorInfo(string $id): DataResponse {
		try {
			$this->initViewer();
			$actor = $this->knownActor($id);

			return $this->success(['actor' => $actor]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * The actor an id names, resolving it remotely only for a caller with a
	 * session.
	 *
	 * The three routes below are public, and the id comes from the query string.
	 * Resolving an unknown one means fetching whatever URL the caller wrote,
	 * storing the actor, and downloading its icon into appdata — so an
	 * unauthenticated caller could use the instance as an HTTP reflector and
	 * fill its disk with bytes of their choosing, a request at a time. Actors
	 * this instance already knows stay readable by anyone (public profiles and
	 * avatars have to work without a login); discovering a new one requires a
	 * session.
	 *
	 * @throws CacheActorDoesNotExistException
	 * @throws Exception
	 */
	private function knownActor(string $id): Person {
		$posAnchor = strpos($id, '#');
		if ($posAnchor !== false) {
			$id = substr($id, 0, $posAnchor);
		}

		try {
			return $this->cacheActorsRequest->getFromId($id);
		} catch (CacheActorDoesNotExistException $e) {
			if ($this->userId === null) {
				$this->logger->debug('[LocalController] refusing to resolve an unknown actor id', [
					'id' => $id,
				]);

				throw new CacheActorDoesNotExistException('unknown actor');
			}

			return $this->cacheActorService->getFromId($id);
		}
	}

	private function getLocalAccountWithCacheFallback(string $username): Person {
		try {
			return $this->cacheActorService->getFromLocalAccount($username);
		} catch (CacheActorDoesNotExistException $e) {
			$this->logger->debug('[LocalController] Rebuilding local actor cache', [
				'username' => $username,
				'error' => $e->getMessage(),
			]);

			try {
				$this->accountService->cacheLocalActorByUsername($username);
			} catch (Exception $cacheError) {
				$this->logger->debug('[LocalController] Local actor cache rebuild failed', [
					'username' => $username,
					'error' => $cacheError->getMessage(),
				]);
			}

			return $this->cacheActorService->getFromLocalAccount($username);
		}
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/global/actor/avatar')]
	public function globalActorAvatar(string $id): Response {
		try {
			$actor = $this->knownActor($id);
			if ($actor->hasIcon()) {
				$avatar = $actor->getIcon();
				$mime = '';
				$document = $this->documentService->getFromCache($avatar->getId(), $mime);

				$response
					= new FileDisplayResponse($document, Http::STATUS_OK, ['Content-Type' => $mime]);
				$response->cacheFor(86400);

				return $response;
			} else {
				throw new InvalidResourceException('no avatar for this Actor');
			}
		} catch (Exception $e) {
			return $this->fail($e, [], Http::STATUS_NOT_FOUND, false);
		}
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/global/actor/header')]
	public function globalActorHeader(string $id): Response {
		try {
			$actor = $this->knownActor($id);
			$headerUrl = $actor->getHeader();
			if ($headerUrl === '') {
				throw new InvalidResourceException('no header for this Actor');
			}

			// The value comes from another instance's JSON, and this route lives
			// on the Nextcloud origin the user trusts: a redirect to it must not
			// be a way to send that user anywhere at all.
			$scheme = strtolower((string)parse_url($headerUrl, PHP_URL_SCHEME));
			if (!in_array($scheme, ['http', 'https'], true)) {
				throw new InvalidResourceException('unsupported header address');
			}

			// Prefer the copy this instance holds: no redirect off-origin at all,
			// and the reader's address is not handed to the remote host.
			try {
				$mime = '';
				$cached = $this->documentService->getCachedFromUrl($headerUrl, $mime);
				$response = new FileDisplayResponse(
					$cached, Http::STATUS_OK, ['Content-Type' => $mime === '' ? 'application/octet-stream' : $mime]
				);
				$response->cacheFor(86400);

				return $response;
			} catch (Exception $e) {
				$this->logger->debug('[LocalController] header is not cached locally', [
					'id' => $actor->getId(),
					'error' => $e->getMessage(),
				]);
			}

			$response = new RedirectResponse($headerUrl);
			$response->cacheFor(86400);

			return $response;
		} catch (Exception $e) {
			return $this->fail($e, [], Http::STATUS_NOT_FOUND, false);
		}
	}

	/**
	 * @throws Exception
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/global/accounts/search')]
	public function globalAccountsSearch(string $search): DataResponse {
		$this->initViewer();

		if (substr($search, 0, 1) === '@') {
			$search = substr($search, 1);
		}

		if ($search === '') {
			return $this->success(['accounts' => [], 'exact' => []]);
		}

		/* Look for an exactly matching account */
		$match = null;
		try {
			$match = $this->cacheActorService->getFromAccount($search, false);
			$match->setCompleteDetails(true);
		} catch (Exception $e) {
		}

		try {
			$accounts = $this->cacheActorService->searchCachedAccounts($search);

			return $this->success(['accounts' => $accounts, 'exact' => $match]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * @throws Exception
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/global/tags/search')]
	public function globalTagsSearch(string $search): DataResponse {
		$this->initViewer();

		if (substr($search, 0, 1) === '#') {
			$search = substr($search, 1);
		}

		if ($search === '') {
			return $this->success(['tags' => [], 'exact' => []]);
		}

		$match = null;
		try {
			$match = $this->hashtagService->getHashtag($search);
		} catch (Exception $e) {
		}

		try {
			$tags = $this->hashtagService->searchHashtags($search, false);

			return $this->success(['tags' => $tags, 'exact' => $match]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * TODO - remove this tag
	 * @throws Exception
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/local/v1/search')]
	public function search(string $search): DataResponse {
		$search = trim($search);
		$this->initViewer();

		$result = [
			'accounts' => $this->searchService->searchAccounts($search),
			'hashtags' => $this->searchService->searchHashtags($search),
			'content' => $this->searchService->searchStreamContent($search)
		];

		return $this->success($result);
	}

	/**
	 * @throws AccountDoesNotExistException
	 */
	private function initViewer(bool $exception = false) {
		if (!isset($this->userId)) {
			if ($exception) {
				throw new AccountDoesNotExistException('userId not defined');
			}

			return;
		}

		try {
			$this->viewer = $this->accountService->getActorFromUserId($this->userId, true);

			$this->streamService->setViewer($this->viewer);
			$this->followService->setViewer($this->viewer);
			$this->cacheActorService->setViewer($this->viewer);
		} catch (Exception $e) {
			if ($exception) {
				throw new AccountDoesNotExistException(
					'unable to initViewer - ' . get_class($e) . ' - ' . $e->getMessage()
				);
			}
		}
	}
}
