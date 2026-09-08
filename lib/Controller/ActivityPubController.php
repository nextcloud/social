<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
use OCA\Social\Model\ActivityPub\OrderedCollection;
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
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialStateService;
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
	private IInitialStateService $initialStateService;
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
		IInitialStateService $initialStateService,
		LoggerInterface $logger,
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
		$this->initialStateService = $initialStateService;
		$this->logger = $logger;

		$this->registerResponder('activity+json', function ($response) {
			$resp = new \OCP\AppFramework\Http\JSONResponse($response->getData());
			$resp->addHeader('Content-Type', 'application/activity+json; charset=utf-8');
			return $resp;
		});
		$this->registerResponder('ld+json; profile="https://www.w3.org/ns/activitystreams"', function ($response) {
			$resp = new \OCP\AppFramework\Http\JSONResponse($response->getData());
			$resp->addHeader('Content-Type', 'ld+json; profile="https://www.w3.org/ns/activitystreams"; charset=utf-8');
			return $resp;
		});
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

			return $this->activityPubSuccess($actor);
		} catch (Exception $e) {
			return $this->fail($e, [], 404);
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
	 * Shared inbox — receives incoming ActivityPub activities from remote servers.
	 *
	 * This is the primary entry point for federation (sharedInbox receives for all
	 * local actors). Flow:
	 *  1. Read raw JSON body
	 *  2. Verify HTTP Signature: ensures the request came from the claimed origin
	 *  3. Check Fediverse authorization (blocklist/allowlist)
	 *  4. Parse JSON into an ActivityPub model object
	 *  5. Verify LinkedDataSignature (if present), else trust HTTP signature origin
	 *  6. Process the incoming activity (varies by type: Follow→auto-accept,
	 *     Create→cache post, Accept→mark follow as accepted)
	 *  7. Send HTTP 200, then async-process the stream cache queue
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return Response
	 */
	public function sharedInbox(): Response {
		try {
			$body = file_get_contents('php://input');

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

			return $this->success();
		} catch (SignatureIsGoneException $e) {
			return $this->success();
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * User-specific inbox — receives incoming ActivityPub activities for a specific user.
	 *
	 * Same logic as sharedInbox but also verifies the local actor exists.
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

			return $this->success();
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
			$actor = $this->cacheActorService->getFromLocalAccount($username);

			$collection = new OrderedCollection();
			$collection->setId($actor->getInbox());
			$collection->setTotalItems(0);

			return $this->activityPubSuccess($collection);
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

			return $this->activityPubSuccess($this->streamService->getOutboxCollection($actor));
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

			return $this->activityPubSuccess($this->followService->getFollowersCollection($actor));
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

			return $this->activityPubSuccess($this->followService->getFollowingCollection($actor));
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

		if ($this->checkSourceActivityStreams()) {
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

			return $this->activityPubSuccess($stream);
		}

		$postId = $this->configService->getSocialUrl() . '@' . $username . '/' . $token;
		try {
			$post = $this->streamService->getStreamById($postId, true);
		} catch (StreamNotFoundException $e) {
			$post = null;
		}

		$serverData = [
			'public' => true,
			'firstrun' => false,
			'setup' => false,
		];

		$this->initialStateService->provideInitialState(Application::APP_ID, 'serverData', $serverData);

		if ($post !== null) {
			$this->initialStateService->provideInitialState(Application::APP_ID, 'item', $post);
		}

		return new TemplateResponse(Application::APP_ID, 'main', []);
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
