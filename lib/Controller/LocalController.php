<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\CacheActorService;
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
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Class LocalController
 *
 * @package OCA\Social\Controller
 */
class LocalController extends Controller {
	use TArrayTools;
	use TNCDataResponse;

	private ?string $userId = null;
	private CacheActorService $cacheActorService;
	private HashtagService $hashtagService;
	private FollowService $followService;
	private BoostService $boostService;
	private LikeService $likeService;
	private PostService $postService;
	private StreamService $streamService;
	private SearchService $searchService;
	private AccountService $accountService;
	private DocumentService $documentService;
	private MiscService $miscService;
	private ConfigService $configService;
	private ?Person $viewer = null;
	private LoggerInterface $logger;

	public function __construct(
		IRequest $request, ?string $userId, AccountService $accountService, CacheActorService $cacheActorService,
		HashtagService $hashtagService,
		FollowService $followService, PostService $postService, StreamService $streamService,
		SearchService $searchService,
		BoostService $boostService, LikeService $likeService, DocumentService $documentService,
		MiscService $miscService,
		ConfigService $configService,
		LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->userId = $userId;
		$this->cacheActorService = $cacheActorService;
		$this->hashtagService = $hashtagService;
		$this->accountService = $accountService;
		$this->streamService = $streamService;
		$this->searchService = $searchService;
		$this->postService = $postService;
		$this->followService = $followService;
		$this->boostService = $boostService;
		$this->likeService = $likeService;
		$this->documentService = $documentService;
		$this->miscService = $miscService;
		$this->configService = $configService;
		$this->logger = $logger;
	}

	/**
	 * Upload file
	 *
	 * @NoAdminRequired
	 */
	public function uploadAttachement(): DataResponse {
		try {
			throw new \BadMethodCallException('uploadAttachment is not implemented yet');
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * Create a new post.
	 *
	 * @NoAdminRequired
	 */
	public function postCreate(string $content = '', array $to = [], ?string $type = null, ?string $replyTo = null, $attachments = null, array $hashtags = []): DataResponse {
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
	 * @NoAdminRequired
	 * @PublicPage
	 * @NoCSRFRequired
	 */
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
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
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
	 * @NoAdminRequired
	 *
	 * @param string $id
	 *
	 * @return DataResponse
	 */
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
	 * Create a new boost.
	 *
	 * @NoAdminRequired
	 */
	public function postBoost(string $postId): DataResponse {
		try {
			$this->initViewer(true);
			$token = '';
			$announce = $this->boostService->create($this->viewer, $postId, $token);

			return $this->success(
				[
					'boost' => $announce,
					'token' => $token
				]
			);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * Delete a boost.
	 *
	 * @NoAdminRequired
	 */
	public function postUnboost(string $postId): DataResponse {
		try {
			$this->initViewer(true);
			$token = '';
			$announce = $this->boostService->delete($this->viewer, $postId, $token);

			return $this->success(
				[
					'boost' => $announce,
					'token' => $token
				]
			);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * Like a post.
	 *
	 * @NoAdminRequired
	 */
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
	 * @NoAdminRequired
	 */
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


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
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


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
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
	 * @NoAdminRequired
	 * @PublicPage
	 */
	public function streamAccount(string $username, int $since = 0, int $limit = 5): DataResponse {
		try {
			$this->initViewer();

			$account = $this->cacheActorService->getFromAccount($username);
			$posts = $this->streamService->getStreamAccount($account->getId(), $since, $limit);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
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
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
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
	 * @NoAdminRequired
	 */
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
	 * @NoAdminRequired
	 */
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
	 * @NoAdminRequired
	 */
	public function streamLiked(int $since = 0, int $limit = 5): DataResponse {
		try {
			$this->initViewer(true);
			$posts = $this->streamService->getStreamLiked($since, $limit);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoAdminRequired
	 */
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


	/**
	 * @NoAdminRequired
	 */
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
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function currentInfo(): DataResponse {
		try {
			if ($this->userId === null) {
				throw new AccountDoesNotExistException('User not logged in');
			}
			$local = $this->accountService->getActorFromUserId($this->userId);
			$actor = $this->cacheActorService->getFromLocalAccount($local->getPreferredUsername());

			return $this->success(['account' => $actor]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoAdminRequired
	 */
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


	/**
	 * @NoAdminRequired
	 */
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


	/**
	 * @NoAdminRequired
	 * @PublicPage
	 */
	public function accountInfo(string $username): DataResponse {
		try {
			$this->initViewer();

			$actor = $this->cacheActorService->getFromLocalAccount($username);
			$actor->setCompleteDetails(true);
			$actor->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($actor, Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * @NoAdminRequired
	 * @PublicPage
	 */
	public function accountFollowers(string $username): DataResponse {
		try {
			$this->initViewer();

			$actor = $this->cacheActorService->getFromLocalAccount($username);
			$following = $this->followService->getFollowers($actor);

			return $this->success($following);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoAdminRequired
	 * @PublicPage
	 */
	public function accountFollowing(string $username): DataResponse {
		try {
			$this->initViewer();

			$actor = $this->cacheActorService->getFromLocalAccount($username);
			$following = $this->followService->getFollowing($actor);

			return $this->success($following);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoAdminRequired
	 * @PublicPage
	 */
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

			if ($isLocal) {
				$this->logger->debug('[LocalController] Local account detected', ['username' => $username]);
				try {
					// Try to get or create the local actor
					$this->accountService->getActorFromUserId($username, true);
					$this->logger->info('[LocalController] Local actor ensured', ['username' => $username]);
				} catch (Exception $e) {
					$this->logger->warning('[LocalController] Failed to ensure local actor', [
						'username' => $username,
						'exception' => $e->getMessage()
					]);
				}
			}

			$actor = $this->cacheActorService->getFromAccount($account);
			$actor->setExportFormat(ACore::FORMAT_LOCAL);

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


	/**
	 * @NoAdminRequired
	 * @PublicPage
	 */
	public function globalActorInfo(string $id): DataResponse {
		try {
			$this->initViewer();
			$actor = $this->cacheActorService->getFromId($id);

			return $this->success(['actor' => $actor]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 */
	public function globalActorAvatar(string $id): Response {
		try {
			$actor = $this->cacheActorService->getFromId($id);
			if ($actor->hasIcon()) {
				$avatar = $actor->getIcon();
				$mime = '';
				$document = $this->documentService->getFromCache($avatar->getId(), $mime);

				$response =
					new FileDisplayResponse($document, Http::STATUS_OK, ['Content-Type' => $mime]);
				$response->cacheFor(86400);

				return $response;
			} else {
				throw new InvalidResourceException('no avatar for this Actor');
			}
		} catch (Exception $e) {
			return $this->fail($e, [], Http::STATUS_NOT_FOUND, false);
		}
	}


	/**
	 * @NoAdminRequired
	 * @throws Exception
	 */
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
	 * @NoAdminRequired
	 * @throws Exception
	 */
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
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws Exception
	 */
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
	 * @NoAdminRequired
	 */
	public function documentsCache(array $documents): DataResponse {
		try {
			$cached = [];
			foreach ($documents as $id) {
				try {
					$document = $this->documentService->cacheRemoteDocument($id);
					$cached[] = $document;
				} catch (Exception $e) {
				}
			}

			return $this->success($cached);
		} catch (Exception $e) {
			return $this->fail($e);
		}
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
