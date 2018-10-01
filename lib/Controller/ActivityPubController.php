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


use daita\Traits\TNCDataResponse;
use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Service\ActivityPubService;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\MiscService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;


class ActivityPubController extends Controller {


	use TNCDataResponse;


	/** @var SocialPubController */
	private $socialPubController;

	/** @var ActivityPubService */
	private $activityPubService;

	/** @var ActorService */
	private $actorService;

	/** @var MiscService */
	private $miscService;


	/**
	 * ActivityPubController constructor.
	 *
	 * @param SocialPubController $socialPubController
	 * @param ActivityPubService $activityPubService
	 * @param ActorService $actorService
	 * @param IRequest $request
	 * @param MiscService $miscService
	 */
	public function __construct(
		SocialPubController $socialPubController, ActivityPubService $activityPubService,
		ActorService $actorService, IRequest $request,
		MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->socialPubController = $socialPubController;
		$this->activityPubService = $activityPubService;
		$this->actorService = $actorService;
		$this->miscService = $miscService;
	}


	/**
	 * returns information about an Actor, based on the username.
	 *
	 * This method should be called when a remote ActivityPub server require information
	 * about a local Social account
	 *
	 * The format is pure Json
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	public function actor(string $username): Response {
		if (!$this->checkSourceActivityStreams()) {
			return $this->socialPubController->actor($username);
		}

		try {
//			$this->activityPubService->generateActor($userId);

			$actor = $this->actorService->getActor($username);

			return $this->directSuccess($actor);
		} catch (Exception $e) {
			return $this->fail($e->getMessage());
		}
	}

	/**
	 * Alias to the actor() method.
	 *
	 * Normal path is /apps/social/users/username
	 * This alias is /apps/social/@username
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	public function aliasactor(string $username): Response {
		return $this->actor($username);
	}


	/**
	 * Shared inbox. does nothing.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return Response
	 */
	public function sharedInbox(): Response {
		return $this->success([]);
	}


	/**
	 * Method is called when a remote ActivityPub server wants to POST in the INBOX of a USER
	 *
	 * Checking that the user exists, and that the header is properly signed.
	 *
	 * Does nothing. Should save data ($body) in database.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param $username
	 *
	 * @return Response
	 */
	public function inbox(string $username): Response {

		try {
			$this->actorService->getActor($username);
			$this->activityPubService->checkRequest($this->request);
//			$this->noteService->receiving(file_get_contents('php://input'));
			$body = file_get_contents('php://input');

			return $this->success([]);
		} catch (Exception $e) {
			return $this->fail($e->getMessage());
		}
	}


	/**
	 * Testing method. does nothing.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return Response
	 */
	public function test(): Response {
		return $this->success(['toto']);
	}


	/**
	 * Outbox. does nothing.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	public function outbox(string $username): Response {
		return $this->success([$username]);
	}


	/**
	 * followers. does nothing.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	public function followers(string $username): Response {
		if (!$this->checkSourceActivityStreams()) {
			return $this->socialPubController->followers($username);
		}

		return $this->success([$username]);
	}


	/**
	 * following. does nothing.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	public function following(string $username): Response {
		if (!$this->checkSourceActivityStreams()) {
			return $this->socialPubController->following($username);
		}

		return $this->success([$username]);
	}


	/**
	 * should return data about a post. do nothing.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 * @param $postId
	 *
	 * @return Response
	 */
	public function displayPost($username, $postId) {
		if (!$this->checkSourceActivityStreams()) {
			return $this->socialPubController->displayPost($username, $postId);
		}

		return $this->success([$username, $postId]);
	}


	/**
	 * Check that the request comes from an ActivityPub server, based on the header.
	 *
	 * If not, should forward to a readable webpage that displays content for navigation.
	 *
	 * @return bool
	 */
	private function checkSourceActivityStreams(): bool {

		// comment this line to display the result that would be return to an ActivityPub service (TEST)
		return true;

		if ($this->request->getHeader('Accept')
			=== 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"') {
			return true;
		}

		return false;
	}
}


