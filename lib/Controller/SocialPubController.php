<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Jonas Sulzer <jonas@violoncello.ch>
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
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialStateService;
use OCP\IL10N;
use OCP\IRequest;


/**
 * Class SocialPubController
 *
 * @package OCA\Social\Controller
 */
class SocialPubController extends Controller {


	use TNCDataResponse;


	/** @var string */
	private $userId;

	/** @var IL10N */
	private $l10n;

	/** @var NavigationController */
	private $navigationController;

	/** @var AccountService */
	private $accountService;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var StreamService */
	private $streamService;

	/** @var ConfigService */
	private $configService;


	/**
	 * SocialPubController constructor.
	 *
	 * @param $userId
	 * @param IRequest $request
	 * @param IL10N $l10n
	 * @param NavigationController $navigationController
	 * @param CacheActorService $cacheActorService
	 * @param AccountService $accountService
	 * @param StreamService $streamService
	 * @param ConfigService $configService
	 */
	public function __construct(
		$userId, IInitialStateService $initialStateService, IRequest $request, IL10N $l10n, NavigationController $navigationController,
		CacheActorService $cacheActorService, AccountService $accountService, StreamService $streamService,
		ConfigService $configService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->userId = $userId;
		$this->initialStateService = $initialStateService;
		$this->l10n = $l10n;
		$this->navigationController = $navigationController;
		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->streamService = $streamService;
		$this->configService = $configService;
	}


	/**
	 * @param $username
	 *
	 * @return Response
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	private function renderPage($username): Response {
		if ($this->userId) {
			return $this->navigationController->navigate('');
		}
		$data = [
			'application' => 'Social'
		];

		$status = Http::STATUS_OK;
		try {
			$actor = $this->cacheActorService->getFromAccount($username);
			$displayName = $actor->getName() !== '' ? $actor->getName() : $actor->getPreferredUsername();
			$data['application'] = $displayName . ' - ' . $data['application'];
		} catch (CacheActorDoesNotExistException $e) {
			$status = Http::STATUS_NOT_FOUND;
		} catch (Exception $e) {
			return $this->fail($e);
		}

		$this->initialStateService->provideInitialState('social', 'serverData', [
			'public' => true,
		]);
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
	 * @throws SocialAppConfigException
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
	 * @throws SocialAppConfigException
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
	 * @throws SocialAppConfigException
	 */
	public function following(string $username): Response {
		return $this->renderPage($username);
	}


	/**
	 * Display the navigation page of the Social app.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 * @param string $token
	 *
	 * @return TemplateResponse
	 * @throws SocialAppConfigException
	 * @throws StreamNotFoundException
	 */
	public function displayPost(string $username, string $token): TemplateResponse {
		try {
			$viewer = $this->accountService->getCurrentViewer();
			$this->streamService->setViewer($viewer);
		} catch (AccountDoesNotExistException $e) {
		}

		$postId = $this->configService->getSocialUrl() . '@' . $username . '/' . $token;

		$stream = $this->streamService->getStreamById($postId, true);
		$data = [
			'id'          => $postId,
			'application' => 'Social'
		];

		$this->initialStateService->provideInitialState(Application::APP_NAME, 'item', $stream );
		$this->initialStateService->provideInitialState(Application::APP_NAME, 'serverData', [
			'public' => ($this->userId === null),
		]);
		return new TemplateResponse(Application::APP_NAME, 'main', $data);
	}


}

