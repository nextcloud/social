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

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RealTokenException;
use OCA\Social\Exceptions\SignatureIsGoneException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Traits\TAsync;
use OCA\Social\Tools\Traits\TNCDataResponse;
use OCA\Social\Tools\Traits\TStringTools;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ActivityPubController extends Controller {
	use TNCDataResponse;
	use TStringTools;
	use TAsync;

	private SocialPubController $socialPubController;
	private FediverseService $fediverseService;
	private CacheActorService $cacheActorService;
	private SignatureService $signatureService;
	private StreamQueueService $streamQueueService;
	private ImportService $importService;
	private AccountService $accountService;
	private FollowService $followService;
	private StreamService $streamService;
	private ConfigService $configService;
	private LoggerInterface $logger;

	public function __construct(
		IRequest $request,
		SocialPubController $socialPubController,
		FediverseService $fediverseService,
		CacheActorService $cacheActorService,
		SignatureService $signatureService,
		StreamQueueService $streamQueueService,
		ImportService $importService,
		AccountService $accountService,
		FollowService $followService,
		StreamService $streamService,
		ConfigService $configService,
		LoggerInterface $logger
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->socialPubController = $socialPubController;
		$this->fediverseService = $fediverseService;
		$this->cacheActorService = $cacheActorService;
		$this->signatureService = $signatureService;
		$this->streamQueueService = $streamQueueService;
		$this->importService = $importService;
		$this->accountService = $accountService;
		$this->followService = $followService;
		$this->streamService = $streamService;
		$this->configService = $configService;
		$this->logger = $logger;
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
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	public function actor(string $username): Response {
		if (!$this->checkSourceActivityStreams()) {
			return $this->socialPubController->actor($username);
		}

		try {
			$actor = $this->cacheActorService->getFromLocalAccount($username);
			$actor->setDisplayW3ContextSecurity(true);

			return $this->directSuccess($actor);
		} catch (Exception $e) {
			http_response_code(404);
			exit();
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
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
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
			$body = file_get_contents('php://input');
			$this->logger->debug('[<<] sharedInbox: ' . $body);

			$requestTime = 0;
			$origin = $this->signatureService->checkRequest($this->request, $body, $requestTime);
			$this->fediverseService->authorized($origin);

			$activity = $this->importService->importFromJson($body);
			if (!$this->signatureService->checkObject($activity)) {
				$activity->setOrigin($origin, SignatureService::ORIGIN_HEADER, $requestTime);
			}

			try {
				$this->importService->parseIncomingRequest($activity);
			} catch (ItemUnknownException $e) {
			}

			$this->async();
			$this->streamQueueService->cacheStreamByToken($activity->getRequestToken());

			// or it will feed the logs.
			exit();
		} catch (SignatureIsGoneException $e) {
			return $this->success();
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * Method is called when a remote ActivityPub server wants to POST in the INBOX of a USER
	 * Checking that the user exists, and that the header is properly signed.
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
			$body = file_get_contents('php://input');
			$this->logger->debug('[<<] inbox', ['body' => $body]);

			$requestTime = 0;
			$origin = $this->signatureService->checkRequest($this->request, $body, $requestTime);
			$this->fediverseService->authorized($origin);

			$actor = $this->cacheActorService->getFromLocalAccount($username);

			$activity = $this->importService->importFromJson($body);
			if (!$this->signatureService->checkObject($activity)) {
				$activity->setOrigin($origin, SignatureService::ORIGIN_HEADER, $requestTime);
			}

			try {
				$this->importService->parseIncomingRequest($activity);
			} catch (ItemUnknownException $e) {
			}

			$this->async();
			$this->streamQueueService->cacheStreamByToken($activity->getRequestToken());

			// or it will feed the logs.
			exit();
		} catch (SignatureIsGoneException $e) {
			return $this->success();
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * Method is called when a remote ActivityPub server wants to GET in the INBOX of a USER
	 * Checking that the user exists, and that the header is properly signed.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	public function getInbox(string $username): Response {
		try {
			$body = file_get_contents('php://input');
			$actor = $this->cacheActorService->getFromLocalAccount($username);

			return $this->success();
		} catch (Exception $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
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
		//		if (!$this->checkSourceActivityStreams()) {
		//			return $this->socialPubController->outbox($username);
		//		}

		try {
			$actor = $this->cacheActorService->getFromLocalAccount($username);

			return $this->directSuccess($this->streamService->getOutboxCollection($actor));
		} catch (Exception $e) {
			return $this->fail($e);
		}
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
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	public function followers(string $username): Response {
		if (!$this->checkSourceActivityStreams()) {
			return $this->socialPubController->followers($username);
		}

		try {
			$actor = $this->cacheActorService->getFromLocalAccount($username);

			return $this->directSuccess($this->followService->getFollowersCollection($actor));
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
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	public function following(string $username): Response {
		if (!$this->checkSourceActivityStreams()) {
			return $this->socialPubController->following($username);
		}

		try {
			$actor = $this->cacheActorService->getFromLocalAccount($username);

			return $this->directSuccess($this->followService->getFollowingCollection($actor));
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * should return data about a post. do nothing.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 * @param string $token
	 *
	 * @return Response
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws StreamNotFoundException
	 */
	public function displayPost(string $username, string $token): Response {
		try {
			return $this->fixToken($username, $token);
		} catch (RealTokenException $e) {
		}

		if (!$this->checkSourceActivityStreams()) {
			return $this->socialPubController->displayPost($username, (int)$token);
		}

		try {
			$viewer = $this->accountService->getCurrentViewer();
			$this->streamService->setViewer($viewer);
		} catch (AccountDoesNotExistException $e) {
		}

		$postId = $this->configService->getSocialUrl() . '@' . $username . '/' . $token;
		try {
			$stream = $this->streamService->getStreamById($postId, true);
		} catch (StreamNotFoundException $e) {
			return $this->fail($e, ['stream' => $postId], Http::STATUS_NOT_FOUND);
		}

		$stream->setCompleteDetails(false);

		return $this->directSuccess($stream);
	}


	/**
	 * @param string $username
	 * @param string $token
	 *
	 * @return Response
	 * @throws RealTokenException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 */
	private function fixToken(string $username, string $token): Response {
		$t = strtolower($token);
		if ($t === 'outbox') {
			return $this->outbox($username);
		}

		if ($t === 'followers') {
			return $this->followers($username);
		}

		if ($t === 'following') {
			return $this->following($username);
		}

		throw new RealTokenException();
	}

	/**
	 * Check that the request comes from an ActivityPub server, based on the header.
	 *
	 * If not, should forward to a readable webpage that displays content for navigation.
	 *
	 * @return bool
	 */
	private function checkSourceActivityStreams(): bool {
		$accepted = [
			'application/ld+json',
			'application/activity+json'
		];

		$accepts = explode(',', $this->request->getHeader('Accept'));
		$accepts = array_map([$this, 'trimHeader'], $accepts);

		foreach ($accepts as $accept) {
			if (in_array($accept, $accepted)) {
				return true;
			}
		}

		return false;
	}


	private function trimHeader(string $header) {
		$header = trim($header);

		$pos = strpos($header, ';');
		if ($pos === false) {
			return $header;
		}

		return substr($header, 0, $pos);
	}
}
