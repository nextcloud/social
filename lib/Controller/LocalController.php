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
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Post;
use OCA\Social\Service\ActivityPub\FollowService;
use OCA\Social\Service\ActivityPub\NoteService;
use OCA\Social\Service\ActivityPub\PersonService;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\PostService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
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

	/** @var PersonService */
	private $personService;

	/** @var FollowService */
	private $followService;

	/** @var ActorService */
	private $actorService;

	/** @var PostService */
	private $postService;

	/** @var NoteService */
	private $noteService;

	/** @var MiscService */
	private $miscService;


	/**
	 * NavigationController constructor.
	 *
	 * @param IRequest $request
	 * @param string $userId
	 * @param PersonService $personService
	 * @param FollowService $followService
	 * @param ActorService $actorService
	 * @param PostService $postService
	 * @param NoteService $noteService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IRequest $request, string $userId, PersonService $personService,
		FollowService $followService, ActorService $actorService,
		PostService $postService, NoteService $noteService,
		MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->userId = $userId;

		$this->actorService = $actorService;
		$this->personService = $personService;
		$this->followService = $followService;
		$this->postService = $postService;
		$this->noteService = $noteService;
		$this->miscService = $miscService;
	}


	/**
	 * Create a new post.
	 *
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @param array $data
	 *
	 * @return DataResponse
	 */
	public function postCreate(array $data): DataResponse {
		try {
			$post = new Post($this->userId);
			$post->setContent($this->get('content', $data, ''));
			$post->setReplyTo($this->get('replyTo', $data, ''));
			$post->setTo($this->getArray('to', $data, []));
			$post->addTo($this->get('to', $data, ''));
			$post->setType($this->get('type', $data, NoteService::TYPE_PUBLIC));

			/** @var ACore $activity */
			$this->postService->createPost($post, $activity);

			return $this->directSuccess($activity->getObject());
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * Create a new post.
	 *
	 * // TODO: Delete the NoCSRF check
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @param string $id
	 *
	 * @return DataResponse
	 */
	public function postDelete(string $id): DataResponse {
		try {
			$note = $this->noteService->getNoteById($id);
			$actor = $this->actorService->getActorFromUserId($this->userId);
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
	 * // TODO: Delete the NoCSRF check
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @return DataResponse
	 */
	public function streamHome(): DataResponse {

		try {
			$actor = $this->actorService->getActorFromUserId($this->userId);
			$posts = $this->noteService->getHomeNotesForActor($actor);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * // TODO: Delete the NoCSRF check
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @return DataResponse
	 */
	public function streamDirect(): DataResponse {

		try {
			$actor = $this->actorService->getActorFromUserId($this->userId);
			$posts = $this->noteService->getDirectNotesForActor($actor);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * Get timeline
	 *
	 * // TODO: Delete the NoCSRF check
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @param int $since
	 * @param int $limit
	 *
	 * @return DataResponse
	 */
	public function streamTimeline(int $since = 0, int $limit = 5): DataResponse {
		try {
			$posts = $this->noteService->getLocalTimeline($since, $limit);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * Get timeline
	 *
	 * // TODO: Delete the NoCSRF check
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @param int $since
	 * @param int $limit
	 *
	 * @return DataResponse
	 */
	public function streamFederated(int $since = 0, int $limit = 5): DataResponse {
		try {
			$posts = $this->noteService->getFederatedTimeline($since, $limit);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 *
	 * // TODO: Delete the NoCSRF check
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @param string $search
	 *
	 * @return DataResponse
	 */
	public function accountsSearch(string $search): DataResponse {
		/* Look for an exactly matching account */
		$match = null;
		try {
			$match = $this->personService->getFromAccount($search, false);
		} catch (Exception $e) {
		}

		try {
			$accounts = $this->personService->searchCachedAccounts($search);

			return $this->success(['accounts' => $accounts, 'exact' => $match]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 *
	 * // TODO: Delete the NoCSRF check
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @param string $account
	 *
	 * @return DataResponse
	 */
	public function accountFollow(string $account): DataResponse {
		try {
			$actor = $this->actorService->getActorFromUserId($this->userId);
			$this->followService->followAccount($actor, $account);

			return $this->success([]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 *
	 * // TODO: Delete the NoCSRF check
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @param string $account
	 *
	 * @return DataResponse
	 */
	public function accountUnfollow(string $account): DataResponse {
		try {
			$actor = $this->actorService->getActorFromUserId($this->userId);
			$this->followService->unfollowAccount($actor, $account);

			return $this->success([]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 *
	 * // TODO: Delete the NoCSRF check
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @param string $id
	 *
	 * @return DataResponse
	 */
	public function actorInfo(string $id): DataResponse {
		try {
			$actor = $this->personService->getFromId($id);

			return $this->success(['actor' => $actor]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

}
