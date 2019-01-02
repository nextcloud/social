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
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\FollowService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;

class SocialPubController extends Controller {


	use TNCDataResponse;

	/** @var string */
	private $userId;

	/** @var IL10N */
	private $l10n;

	/** @var AccountService */
	private $accountService;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var FollowService */
	private $followService;

	/** @var NavigationController */
	private $navigationController;


	/**
	 * SocialPubController constructor.
	 *
	 * @param $userId
	 * @param IRequest $request
	 * @param IL10N $l10n
	 * @param CacheActorService $cacheActorService
	 * @param NavigationController $navigationController
	 */
	public function __construct(
		$userId,
		IRequest $request,
		IL10N $l10n,
		CacheActorService $cacheActorService,
		NavigationController $navigationController
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->userId = $userId;
		$this->l10n = $l10n;
		$this->cacheActorService = $cacheActorService;
		$this->navigationController = $navigationController;
	}

	private function renderPage($username): Response {
		if ($this->userId) {
			return $this->navigationController->navigate('');
		}
		$data = [
			'serverData' => [
				'public' => true,
			],
			'application' => 'Social'
		];

		$status = Http::STATUS_OK;
		try {
			$actor = $this->cacheActorService->getFromLocalAccount($username);
			$displayName = $actor->getName() !== '' ? $actor->getName() : $actor->getPreferredUsername();
			$data['application'] = $displayName . ' - ' . $data['application'];
		} catch (CacheActorDoesNotExistException $e) {
			$status = Http::STATUS_NOT_FOUND;
		} catch (\Exception $e) {
			return $this->fail($e);
		}
		$page = new PublicTemplateResponse(Application::APP_NAME, 'main', $data);
		$page->setStatus($status);
		$page->setHeaderTitle($this->l10n->t('Social'));

		return $page;
	}


	/**
	 * return webpage content for human navigation.
	 * Should return information about a Social account, based on username.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return Response
	 * @throws UrlCloudException
	 */
	public function actor(string $username): Response {
		return $this->renderPage($username);
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
	 * @throws UrlCloudException
	 */
	public function followers(string $username): Response {
		return $this->renderPage($username);
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
	 * @throws UrlCloudException
	 */
	public function following(string $username): Response {
		return $this->renderPage($username);
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

