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
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	public function actor(string $username) {
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
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	public function aliasactor(string $username) {
		return $this->actor($username);
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return Response
	 */
	public function sharedInbox() {
		return $this->success([]);
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return Response
	 */
	public function inbox($username) {

		try {
			$this->activityPubService->checkRequest($this->request);
//			$this->noteService->receiving(file_get_contents('php://input'));
			$body = file_get_contents('php://input');
$this->miscService->log('### ' . $body);
			return $this->success([]);
		} catch (Exception $e) {
			return $this->fail($e->getMessage());
		}
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	public function test($username, $body) {

		return $this->success([$username]);
//		$this->miscService->log('####  ' . $toto . '   ' . json_encode($_SERVER) . '     ' . json_encode($_POST));
//		try {
//			return $this->success(['author' => $author]);
//		} catch (Exception $e) {
//		return $this->fail('ddsaads');
//		}
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	public function outbox($username) {
		return $this->success([$username]);
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	public function followers($username) {
		if (!$this->checkSourceActivityStreams()) {
			return $this->socialPubController->followers($username);
		}

		return $this->success([$username]);
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	public function following($username) {
		if (!$this->checkSourceActivityStreams()) {
			return $this->socialPubController->following($username);
		}

		return $this->success([$username]);
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 * @param $postId
	 *
	 * @return Response
	 */
	public function displayPost($username, $postId) {
		return $this->success([$username, $postId]);
	}


	/**
	 *
	 */
	private function checkSourceActivityStreams() {

		return true;
		if ($this->request->getHeader('Accept')
			=== 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"') {
			return true;
		}

		return false;
	}
}


