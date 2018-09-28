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
use OCA\Social\AppInfo\Application;
use OCA\Social\Service\ActivityPubService;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\MiscService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class SocialPubController extends Controller {


	use TNCDataResponse;

	/** @var ActivityPubService */
	private $activityPubService;

	/** @var ActorService */
	private $actorService;

	/** @var MiscService */
	private $miscService;


	/**
	 * ActivityPubController constructor.
	 *
	 * @param ActivityPubService $activityPubService
	 * @param ActorService $actorService
	 * @param IRequest $request
	 * @param MiscService $miscService
	 */
	public function __construct(
		ActivityPubService $activityPubService, ActorService $actorService, IRequest $request,
		MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->activityPubService = $activityPubService;
		$this->actorService = $actorService;
		$this->miscService = $miscService;
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 e*
	 * @param string $username
	 *
	 * @return Response
	 */
	public function actor(string $username) {
		return new TemplateResponse(Application::APP_NAME, 'actor', [], 'blank');
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
		return new TemplateResponse(Application::APP_NAME, 'followers', [], 'blank');
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
		return new TemplateResponse(Application::APP_NAME, 'following', [], 'blank');
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

}


