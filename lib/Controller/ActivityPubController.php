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
use Exception;
use OC\AppFramework\Http;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\NotesRequest;
use OCA\Social\Exceptions\SignatureIsGoneException;
use OCA\Social\Exceptions\UnknownItemException;
use OCA\Social\Service\ActivityPub\FollowService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\MiscService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;


class ActivityPubController extends Controller {


	use TNCDataResponse;


	/** @var SocialPubController */
	private $socialPubController;

	/** @var ActivityService */
	private $activityService;

	/** @var ImportService */
	private $importService;

	/** @var FollowService */
	private $followService;

	/** @var ActorService */
	private $actorService;

	/** @var NotesRequest */
	private $notesRequest;

	/** @var MiscService */
	private $miscService;

	/** @var NavigationController */
	private $navigationController;


	/**
	 * ActivityPubController constructor.
	 *
	 * @param IRequest $request
	 * @param SocialPubController $socialPubController
	 * @param ActivityService $activityService
	 * @param ImportService $importService
	 * @param FollowService $followService
	 * @param ActorService $actorService
	 * @param NotesRequest $notesRequest
	 * @param MiscService $miscService
	 */
	public function __construct(
		IRequest $request, SocialPubController $socialPubController,
		ActivityService $activityService, ImportService $importService,
		FollowService $followService, ActorService $actorService, NotesRequest $notesRequest,
		NavigationController $navigationController,
		MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->socialPubController = $socialPubController;
		$this->navigationController = $navigationController;

		$this->activityService = $activityService;
		$this->importService = $importService;
		$this->followService = $followService;
		$this->actorService = $actorService;
		$this->notesRequest = $notesRequest;
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
			$actor = $this->actorService->getActor($username);

//			$actor->setTopLevel(true);

			return $this->directSuccess($actor);
		} catch (Exception $e) {
			return $this->fail($e);
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
	public function actorAlias(string $username): Response {
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

		try {
			$this->activityService->checkRequest($this->request);

			$body = file_get_contents('php://input');
			$this->miscService->log('Shared Inbox: ' . $body);

			$activity = $this->importService->import($body);
			try {
				$this->importService->parse($activity);
			} catch (UnknownItemException $e) {
			}

			return $this->success([]);
		} catch (SignatureIsGoneException $e) {
			return $this->fail($e, [], Http::STATUS_GONE);
		} catch (Exception $e) {
			return $this->fail($e);
		}
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
	 * @param string $username
	 *
	 * @return Response
	 */
	public function inbox(string $username): Response {

		try {

			$this->activityService->checkRequest($this->request);
			$body = file_get_contents('php://input');

			// TODO - check the recipient <-> username
//			$actor = $this->actorService->getActor($username);

			$this->miscService->log('Inbox: ' . $body);

			$activity = $this->importService->import($body);
			try {
				$this->importService->parse($activity);
			} catch (UnknownItemException $e) {
			}

			return $this->success([]);
		} catch (SignatureIsGoneException $e) {
			return $this->fail($e, [], Http::STATUS_GONE);
		} catch (Exception $e) {
			return $this->fail($e);
		}
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
	public function followers(string $username, $data): Response {

		if (!$this->checkSourceActivityStreams()) {
			return $this->socialPubController->followers($username);
		}

		try {
			$actor = $this->actorService->getActor($username);
			$followers = $this->followService->getFollowers($actor);

//			$followers->setTopLevel(true);

			return $this->directSuccess($followers);
		} catch (Exception $e) {
			return $this->fail($e);
		}
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

		// uncomment this line to display the result that would be return to an ActivityPub service (TEST)
		// return true;

		if ($this->request->getHeader('Accept')
			=== 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"') {
			return true;
		}

		return false;
	}
}


