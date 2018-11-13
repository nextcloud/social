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


use daita\MySmallPhpTools\Traits\TArrayTools;
use daita\MySmallPhpTools\Traits\TNCDataResponse;
use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Model\Post;
use OCA\Social\Service\ActivityPub\NoteService;
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
	 * @param ActorService $actorService
	 * @param PostService $postService
	 * @param NoteService $noteService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IRequest $request, string $userId, ActorService $actorService, PostService $postService,
		NoteService $noteService,
		MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->userId = $userId;

		$this->actorService = $actorService;
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
	public function newPost(array $data): DataResponse {
		try {
			$post = new Post($this->userId);
			$post->setContent($this->get('content', $data, ''));
			$post->setReplyTo($this->get('replyTo', $data, ''));
			$post->setTo($this->getArray('to', $data, []));
			$post->addTo($this->get('to', $data, ''));
			$post->setType($this->get('type', $data, NoteService::TYPE_PUBLIC));

			$result = $this->postService->createPost($post);

			return $this->success($result);
		} catch (Exception $e) {
			return $this->fail($e->getMessage());
		}
	}


	/**
	 * Get timeline
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @return DataResponse
	 */
	public function timeline(): DataResponse {

//		$this->miscService->log('timeline: ' . json_encode($data));

		try {
			$posts = $this->noteService->getTimeline();

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e->getMessage());
		}
	}


	/**
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @return DataResponse
	 */
	public function direct(): DataResponse {

		try {
			$actor = $this->actorService->getActorFromUserId($this->userId);
			$posts = $this->noteService->getNotesForActor($actor);

			return $this->success($posts);
		} catch (Exception $e) {
			return $this->fail($e->getMessage());
		}


	}

}
