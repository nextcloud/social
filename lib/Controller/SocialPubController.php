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
use OCA\Social\AppInfo\Application;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\MiscService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class SocialPubController extends Controller {


	use TNCDataResponse;

	/** @var ActivityService */
	private $activityService;

	/** @var ActorService */
	private $actorService;

	/** @var MiscService */
	private $miscService;


	/**
	 * SocialPubController constructor.
	 *
	 * @param ActivityService $activityService
	 * @param ActorService $actorService
	 * @param IRequest $request
	 * @param MiscService $miscService
	 */
	public function __construct(
		ActivityService $activityService, ActorService $actorService, IRequest $request,
		MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->activityService = $activityService;
		$this->actorService = $actorService;
		$this->miscService = $miscService;
	}


	/**
	 * return webpage content for human navigation.
	 * Should return information about a Social account, based on username.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * e*
	 *
	 * @param string $username
	 *
	 * @return TemplateResponse
	 */
	public function actor(string $username): TemplateResponse {
		return new TemplateResponse(Application::APP_NAME, 'actor', [], 'blank');
	}


	/**
	 * return webpage content for human navigation.
	 * Should return followers of a Social account, based on username.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return TemplateResponse
	 */
	public function followers(string $username): TemplateResponse {
		return new TemplateResponse(Application::APP_NAME, 'followers', [], 'blank');
	}


	/**
	 * return webpage content for human navigation.
	 * Should return following of a Social account, based on username.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return TemplateResponse
	 */
	public function following(string $username): TemplateResponse {
		return new TemplateResponse(Application::APP_NAME, 'following', [], 'blank');
	}


	/**
	 * Should return post, do nothing.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 * @param $postId
	 *
	 * @return Response
	 */
	public function displayPost(string $username, int $postId) {
		return $this->success([$username, $postId]);
	}

}


