<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Social\Controller;


use daita\MySmallPhpTools\Traits\Nextcloud\TNCDataResponse;
use daita\MySmallPhpTools\Traits\TArrayTools;
use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\NoteService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\SearchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;


/**
 * Class LocalController
 *
 * @package OCA\Social\Controller
 */
class LocalController extends Controller {


	use TArrayTools;
	use TNCDataResponse;


	/** @var string */
	private $userId;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var FollowService */
	private $followService;

	/** @var PostService */
	private $postService;

	/** @var NoteService */
	private $noteService;

	/** @var SearchService */
	private $searchService;

	/** @var AccountService */
	private $accountService;

	/** @var DocumentService */
	private $documentService;

	/** @var MiscService */
	private $miscService;


	/** @var Person */
	private $viewer;


	/**
	 * LocalController constructor.
	 *
	 * @param IRequest $request
	 * @param string $userId
	 * @param AccountService $accountService
	 * @param CacheActorService $cacheActorService
	 * @param FollowService $followService
	 * @param PostService $postService
	 * @param NoteService $noteService
	 * @param SearchService $searchService
	 * @param DocumentService $documentService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IRequest $request, $userId, AccountService $accountService,
		CacheActorService $cacheActorService, FollowService $followService,
		PostService $postService, NoteService $noteService, SearchService $searchService,
		DocumentService $documentService, MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->userId = $userId;
		$this->cacheActorService = $cacheActorService;
		$this->accountService = $accountService;
		$this->noteService = $noteService;
		$this->searchService = $searchService;
		$this->postService = $postService;
		$this->followService = $followService;
		$this->documentService = $documentService;
		$this->miscService = $miscService;
	}


	/**
	 * Create a new post.
	 *
	 * @NoAdminRequired
	 *
	 * @param array $data
	 *
	 * @return DataResponse
	 */
	public function postCreate(array $data): DataResponse {
		try {
			$actor = $this->accountService->getActorFromUserId($this->userId);

			$post = new Post($actor);
			$post->setContent($this->get('content', $data, ''));
			$post->setReplyTo($this->get('replyTo', $data, ''));
			$post->setTo($this->getArray('to', $data, []));
			$post->addTo($this->get('to', $data, ''));
			$post->setType($this->get('type', $data, Note::TYPE_PUBLIC));
			$post->setHashtags($this->getArray('hashtags', $data, []));

			/** @var ACore $activity */
			$token = $this->postService->createPost($post, $activity);

			return $this->success(
				[
					'post'  => $activity->getObject(),
					'token' => $token
				]
			);
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
			$note = $this->noteService->getNoteById($id);
			$actor = $this->accountService->getActorFromUserId($this->userId);
			if ($note->getAttributedTo() !== $actor->getId()) {
				throw new InvalidResourceException('user have no rights');
			}

			$this->noteService->deleteLocalNote($note);

			return $this->success();
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoAdminRequired
	 *
	 * @param int $since
	 * @param int $limit
	 *
	 * @return DataResponse
	 */
	public function streamHome($since = 0, int $limit = 5): DataResponse {
		try {
			$this->initViewer(true);
			$posts = $this->noteService->getStreamHome($this->viewer, $since, $limit);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoAdminRequired
	 *
	 * @param int $since
	 * @param int $limit
	 *
	 * @return DataResponse
	 */
	public function streamNotifications($since = 0, int $limit = 5): DataResponse {
		try {
			$this->initViewer(true);
			$posts = $this->noteService->getStreamNotifications($this->viewer, $since, $limit);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoAdminRequired
	 * @PublicPage
	 *
	 * @param string $username
	 * @param int $since
	 * @param int $limit
	 *
	 * @return DataResponse
	 */
	public function streamAccount(string $username, $since = 0, int $limit = 5): DataResponse {
		try {
			$this->initViewer();

			$account = $this->cacheActorService->getFromLocalAccount($username);
			$posts = $this->noteService->getStreamAccount($account->getId(), $since, $limit);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoAdminRequired
	 *
	 * @param int $since
	 * @param int $limit
	 *
	 * @return DataResponse
	 */
	public function streamDirect(int $since = 0, int $limit = 5): DataResponse {
		try {
			$this->initViewer();
			$posts = $this->noteService->getStreamDirect($this->viewer, $since, $limit);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * Get timeline
	 *
	 * @NoAdminRequired
	 *
	 * @param int $since
	 * @param int $limit
	 *
	 * @return DataResponse
	 */
	public function streamTimeline(int $since = 0, int $limit = 5): DataResponse {
		try {
			$posts = $this->noteService->getStreamLocalTimeline($since, $limit);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * Get timeline
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 *
	 * @param int $since
	 * @param int $limit
	 *
	 * @return DataResponse
	 */
	public function streamTag(string $hashtag, int $since = 0, int $limit = 5): DataResponse {
		try {
			$posts = $this->noteService->getStreamLocalTag($hashtag, $since, $limit);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * Get timeline
	 *
	 * @NoAdminRequired
	 *
	 * @param int $since
	 * @param int $limit
	 *
	 * @return DataResponse
	 */
	public function streamFederated(int $since = 0, int $limit = 5): DataResponse {
		try {
			$posts = $this->noteService->getStreamGlobalTimeline($since, $limit);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoAdminRequired
	 *
	 * @param string $account
	 *
	 * @return DataResponse
	 */
	public function actionFollow(string $account): DataResponse {
		try {
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
	 *
	 * @param string $account
	 *
	 * @return DataResponse
	 */
	public function actionUnfollow(string $account): DataResponse {
		try {
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
			$local = $this->accountService->getActorFromUserId($this->userId);
			$actor = $this->cacheActorService->getFromLocalAccount($local->getPreferredUsername());

			return $this->success(['account' => $actor]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function currentFollowers(): DataResponse {
		try {
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
	 *
	 * @return DataResponse
	 */
	public function currentFollowing(): DataResponse {
		try {
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
	 *
	 * @param string $username
	 *
	 * @return DataResponse
	 */
	public function accountInfo(string $username): DataResponse {
		try {
			$this->initViewer();

			$actor = $this->cacheActorService->getFromLocalAccount($username);
			$actor->setCompleteDetails(true);

			return $this->success(['account' => $actor]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoAdminRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return DataResponse
	 */
	public function accountFollowers(string $username): DataResponse {
		try {
			$this->initViewer();

			$actor = $this->cacheActorService->getFromLocalAccount($username);
			$followers = $this->followService->getFollowers($actor);

			return $this->success($followers);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoAdminRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return DataResponse
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
	 *
	 * @param string $account
	 *
	 * @return DataResponse
	 */
	public function globalAccountInfo(string $account): DataResponse {
		try {
			$this->initViewer();

			$actor = $this->cacheActorService->getFromAccount($account);

			return $this->success(['account' => $actor]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoAdminRequired
	 *
	 * @param string $id
	 *
	 * @return DataResponse
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
	 *
	 * @param string $id
	 *
	 * @return DataResponse
	 */
	public function globalActorAvatar(string $id): Response {
		try {
			$actor = $this->cacheActorService->getFromId($id);
			if ($actor->gotIcon()) {
				$avatar = $actor->getIcon();
				$document = $this->documentService->getFromCache($avatar->getId());

				$response = new FileDisplayResponse($document);
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
	 *
	 * @param string $search
	 *
	 * @return DataResponse
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


	/**     // TODO - remove this tag
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 *
	 * @param string $search
	 *
	 * @return DataResponse
	 * @throws Exception
	 */
	public function search(string $search): DataResponse {
		$search = trim($search);
		$this->initViewer();

		$result = [
			'accounts' => $this->searchService->searchAccounts($search),
			'hashtags' => $this->searchService->searchHashtags($search),
			'content'  => $this->searchService->searchStreamContent($search)
		];

		return $this->success($result);
	}


	/**
	 * @NoAdminRequired
	 *
	 * @param array $documents
	 *
	 * @return DataResponse
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
	 *
	 * @param bool $exception
	 *
	 * @throws AccountDoesNotExistException
	 */
	private function initViewer(bool $exception = false) {
		if (!isset($this->userId)) {
			return;
		}
		try {
			$this->viewer = $this->accountService->getActorFromUserId($this->userId, true);

			$this->followService->setViewerId($this->viewer->getId());
			$this->cacheActorService->setViewerId($this->viewer->getId());
		} catch (Exception $e) {
			if ($exception) {
				throw new AccountDoesNotExistException();
			}
		}
	}

}

