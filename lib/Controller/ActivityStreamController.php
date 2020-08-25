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
use OC\User\NoUserException;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\AccountAlreadyExistsException;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActivityStream\ClientApp;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;


class ActivityStreamController extends Controller {


	use TNCDataResponse;


	/** @var AccountService */
	private $accountService;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var ClientService */
	private $clientService;

	/** @var FollowService */
	private $followService;

	/** @var StreamService */
	private $streamService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/** @var string */
	private $bearer = '';

	/** @var Person */
	private $viewer;


	/**
	 * ActivityStreamController constructor.
	 *
	 * @param IRequest $request
	 * @param AccountService $accountService
	 * @param CacheActorService $cacheActorService
	 * @param ClientService $clientService
	 * @param FollowService $followService
	 * @param StreamService $streamService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IRequest $request, AccountService $accountService, CacheActorService $cacheActorService,
		ClientService $clientService, FollowService $followService, StreamService $streamService,
		ConfigService $configService,
		MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->clientService = $clientService;
		$this->followService = $followService;
		$this->streamService = $streamService;
		$this->configService = $configService;
		$this->miscService = $miscService;

		$authHeader = trim($this->request->getHeader('Authorization'));
		list($authType, $authToken) = explode(' ', $authHeader);
		if (strtolower($authType) === 'bearer') {
			$this->bearer = $authToken;
		}
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $website
	 * @param string $redirect_uris
	 * @param string $scopes
	 * @param string $client_name
	 *
	 * @return Response
	 */
	public function apps(
		string $website = '', string $redirect_uris = '', string $scopes = '', string $client_name = ''
	): Response {
		$clientApp = new ClientApp();
		$clientApp->setWebsite($website);
		$clientApp->setRedirectUri($redirect_uris);
		$clientApp->setScopesFromString($scopes);
		$clientApp->setName($client_name);

		$this->clientService->createClient($clientApp);
		$this->miscService->log('### ' . json_encode($clientApp));

		return $this->directSuccess($clientApp);
	}


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 *
	 * @return DataResponse
	 * @throws SocialAppConfigException
	 * @throws AccountAlreadyExistsException
	 * @throws ActorDoesNotExistException
	 * @throws ItemAlreadyExistsException
	 * @throws UrlCloudException
	 * @throws NoUserException
	 */
	public function authorize(): DataResponse {
		$userId = 'cult';

		$account = $this->accountService->getActorFromUserId($userId);
		$clientId = (string)$this->request->getParam('client_id', '');
		$responseType = (string)$this->request->getParam('response_type', '');
		$redirectUri = (string)$this->request->getParam('redirect_uri', '');

		if ($responseType !== 'code') {
			return new DataResponse(['error' => 'invalid_type'], Http::STATUS_BAD_REQUEST);
		}

//		$this->clientService->assignAccount($clientId, $account);
		$code = 'test1234';

		if ($redirectUri !== '') {
			header('Location: ' . $redirectUri . '?code=' . $code);
			exit();
		}

//		return new DataResponse(
//			[
//				'access_token' => '',
//				"token_type"   => "Bearer",
//				"scope"        => "read write follow push",
//				"created_at"   => 1573979017
//			], Http::STATUS_OK
//		);

	}


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 *
	 * @return DataResponse
	 */
	public function token() {
		$body = file_get_contents('php://input');
		$this->miscService->log('[<<] : ' . $body, 1);
////code=test1234&grant_type=authorization_code&
//client_secret=amJWTrlnZEhe44aXHsW2xlsTLD8g0DqabDDJ7jdp&
//redirect_uri=https%3A%2F%2Forg.mariotaku.twidere%2Fauth%2Fcallback%2Fmastodon&
//client_id=ihyiNapjftENlY2dZCbbfLHYoloB1HbpWQyLGtvr
		return new DataResponse(
			[
				"access_token" => "ZA-Yj3aBD8U8Cm7lKUp-lm9O9BmDgdhHzDeqsY8tlL0",
				"token_type"   => "Bearer",
				"scope"        => "read write follow push",
				"created_at"   => time()
			], 200
		);
	}


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 *
	 * @return DataResponse
	 */
	public function appsCredentials() {
		return new DataResponse(
			[
				'name'    => 'Twidere for Android',
				'website' => 'https://github.com/TwidereProject/'
			], 200
		);
	}


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 *
	 * @return DataResponse
	 */
	public function accountsCredentials() {
		return new DataResponse(
			[
				"id"              => "137148",
				"username"        => "cult",
				"acct"            => "cult",
				"display_name"    => "Maxence Lange",
				"locked"          => false,
				"bot"             => false,
				"discoverable"    => null,
				"group"           => false,
				"created_at"      => "2017-05-11T09=>20=>28.055Z",
				"note"            => "\u003cp\u003e\u003c/p\u003e",
				"url"             => "https://test.artificial-owl.com/index.php/apps/social/@cult",
				"avatar"          => "https://mastodon.social/avatars/original/missing.png",
				"avatar_static"   => "https://mastodon.social/avatars/original/missing.png",
				"header"          => "https://mastodon.social/headers/original/missing.png",
				"header_static"   => "https://mastodon.social/headers/original/missing.png",
				"followers_count" => 9,
				"following_count" => 5,
				"statuses_count"  => 13,
				"last_status_at"  => "2019-09-15",
				"source"          => [
					"privacy"               => "public",
					"sensitive"             => false,
					"language"              => null,
					"note"                  => "",
					"fields"                => [],
					"follow_requests_count" => 0
				],
				"emojis"          => [],
				"fields"          => []
			], 200
		);
	}


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 *
	 * @param string $timeline
	 * @param int $limit
	 *
	 * @return DataResponse
	 */
	public function timelines(string $timeline, int $limit = 20): DataResponse {
		try {
			$this->initViewer(true);
			$posts = $this->streamService->getStreamHome(0, $limit, Stream::FORMAT_LOCAL);

			return new DataResponse($posts, 200);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 *
	 * @param bool $exception
	 *
	 * @return bool
	 * @throws AccountDoesNotExistException
	 */
	private function initViewer(bool $exception = false): bool {
		if ($this->bearer === '') {
//			if ($exception) {
//				throw new AccountDoesNotExistException('userId not defined');
//			}
//
//			return false;
		}

		try {
			$this->viewer = $this->cacheActorService->getFromLocalAccount('cult');

			$this->streamService->setViewer($this->viewer);
			$this->followService->setViewer($this->viewer);
			$this->cacheActorService->setViewer($this->viewer);
		} catch (Exception $e) {
			if ($exception) {
				throw new AccountDoesNotExistException(
					'unable to initViewer - ' . get_class($e) . ' - ' . $e->getMessage()
				);
			}

			return false;
		}

		return true;
	}

}


