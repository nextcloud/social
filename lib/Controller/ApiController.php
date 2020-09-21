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
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\TimelineOptions;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;


/**
 * Class ApiController
 *
 * @package OCA\Social\Controller
 */
class ApiController extends Controller {


	use TNCDataResponse;


	/** @var IUserSession */
	private $userSession;

	/** @var InstanceService */
	private $instanceService;

	/** @var ClientService */
	private $clientService;

	/** @var AccountService */
	private $accountService;

	/** @var CacheActorService */
	private $cacheActorService;

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

	/** @var SocialClient */
	private $client;

	/** @var Person */
	private $viewer;


	/**
	 * ActivityStreamController constructor.
	 *
	 * @param IRequest $request
	 * @param IUserSession $userSession
	 * @param InstanceService $instanceService
	 * @param ClientService $clientService
	 * @param AccountService $accountService
	 * @param CacheActorService $cacheActorService
	 * @param FollowService $followService
	 * @param StreamService $streamService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IRequest $request, IUserSession $userSession, InstanceService $instanceService,
		ClientService $clientService, AccountService $accountService, CacheActorService $cacheActorService,
		FollowService $followService, StreamService $streamService, ConfigService $configService,
		MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->userSession = $userSession;
		$this->instanceService = $instanceService;
		$this->clientService = $clientService;
		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->followService = $followService;
		$this->streamService = $streamService;
		$this->configService = $configService;
		$this->miscService = $miscService;

		$authHeader = trim($this->request->getHeader('Authorization'));
		if (strpos($authHeader, ' ')) {
			list($authType, $authToken) = explode(' ', $authHeader);
			if (strtolower($authType) === 'bearer') {
				$this->bearer = $authToken;
			}
		}
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return DataResponse
	 */
	public function appsCredentials() {
		try {
			$this->initViewer(true);

			if ($this->client === null) {
				return new DataResponse(
					[
						'name'    => 'Nextcloud Social',
						'website' => 'https://github.com/nextcloud/social/'
					], Http::STATUS_OK
				);
			} else {
				return new DataResponse(
					[
						'name'    => $this->client->getAppName(),
						'website' => $this->client->getAppWebsite()
					], Http::STATUS_OK
				);
			}
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}

	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return DataResponse
	 */
	public function verifyCredentials() {
		try {
			$this->initViewer(true);

			return new DataResponse($this->viewer, Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return DataResponse
	 */
	public function customEmojis(): DataResponse {
		return new DataResponse([], Http::STATUS_OK);
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return DataResponse
	 */
	public function savedSearches(): DataResponse {
		try {
			$this->initViewer(true);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return DataResponse
	 */
	public function notifications(): DataResponse {
		try {
			$this->initViewer(true);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return DataResponse
	 * @throws InstanceDoesNotExistException
	 */
	public function instance(): DataResponse {
		$local = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);

		return new DataResponse($local, Http::STATUS_OK);
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $timeline
	 * @param int $limit
	 *
	 * @return DataResponse
	 */
	public function timelines(string $timeline, int $limit = 20): DataResponse {
		$options = new TimelineOptions($this->request);
		$options->setFormat(Stream::FORMAT_LOCAL);
		$options->setTimeline($timeline);
		$options->setLimit($limit);

		try {
			$this->initViewer(true);
			$posts = $this->streamService->getTimeline($options);

			return new DataResponse($posts, Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}
	}


	/**
	 *
	 * @param bool $exception
	 *
	 * @return bool
	 * @throws ClientNotFoundException
	 */
	private function initViewer(bool $exception = false): bool {
		try {
			$userId = $this->currentSession();

			$this->miscService->log(
				'[ApiController] initViewer: ' . $userId . ' (bearer=' . $this->bearer . ')', 0
			);

			$account = $this->accountService->getActorFromUserId($userId);
			$this->viewer = $this->cacheActorService->getFromLocalAccount($account->getPreferredUsername());
			$this->viewer->setExportFormat(ACore::FORMAT_LOCAL);

			$this->streamService->setViewer($this->viewer);
			$this->followService->setViewer($this->viewer);
			$this->cacheActorService->setViewer($this->viewer);

			return true;
		} catch (Exception $e) {
			if ($exception) {
				throw new ClientNotFoundException('the access_token was revoked');
			}
		}

		return false;
	}


	/**
	 * @return string
	 * @throws AccountDoesNotExistException
	 * @throws ClientNotFoundException
	 */
	private function currentSession(): string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return $user->getUID();
		}

		if ($this->bearer !== '') {
			$this->client = $this->clientService->getFromToken($this->bearer);

			return $this->client->getAuthUserId();
		}

		throw new AccountDoesNotExistException('userId not defined');
	}


	/**
	 * @param string $error
	 *
	 * @return DataResponse
	 */
	private function error(string $error): DataResponse {
		return new DataResponse(['error' => $error], Http::STATUS_UNAUTHORIZED);
	}

}


