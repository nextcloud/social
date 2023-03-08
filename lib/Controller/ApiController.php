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
use OCA\Social\AP;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Exceptions\UnknownProbeException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActionService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Traits\TNCDataResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Class ApiController
 *
 * @package OCA\Social\Controller
 */
class ApiController extends Controller {
	use TNCDataResponse;

	private IURLGenerator $urlGenerator;
	private IUserSession $userSession;
	private LoggerInterface $logger;
	private InstanceService $instanceService;
	private ClientService $clientService;
	private AccountService $accountService;
	private CacheActorService $cacheActorService;
	private CacheDocumentService $cacheDocumentService;
	private DocumentService $documentService;
	private FollowService $followService;
	private StreamService $streamService;
	private ActionService $actionService;
	private PostService $postService;
	private ConfigService $configService;

	private string $bearer = '';
	private ?SocialClient $client = null;
	private ?Person $viewer = null;

	public function __construct(
		IRequest $request,
		IURLGenerator $urlGenerator,
		IUserSession $userSession,
		LoggerInterface $logger,
		InstanceService $instanceService,
		ClientService $clientService,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		CacheDocumentService $cacheDocumentService,
		DocumentService $documentService,
		FollowService $followService,
		StreamService $streamService,
		ActionService $actionService,
		PostService $postService,
		ConfigService $configService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->urlGenerator = $urlGenerator;
		$this->userSession = $userSession;
		$this->logger = $logger;
		$this->instanceService = $instanceService;
		$this->clientService = $clientService;
		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->cacheDocumentService = $cacheDocumentService;
		$this->documentService = $documentService;
		$this->followService = $followService;
		$this->streamService = $streamService;
		$this->actionService = $actionService;
		$this->postService = $postService;
		$this->configService = $configService;

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
						'name' => 'Nextcloud Social',
						'website' => 'https://github.com/nextcloud/social/'
					], Http::STATUS_OK
				);
			} else {
				return new DataResponse(
					[
						'name' => $this->client->getAppName(),
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
	 * @throws InstanceDoesNotExistException
	 */
	public function instance(): DataResponse {
		$local = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);

		return new DataResponse($local, Http::STATUS_OK);
	}


	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @return DataResponse
	 */
	public function statusNew(): DataResponse {
		try {
			$this->initViewer(true);

			$input = file_get_contents('php://input');
			$this->logger->debug('[ApiController] statusNew: ' . $input);

			$status = new Status();
			$status->import($this->convertInput($input));

			$post = new Post($this->accountService->getActorFromUserId($this->currentSession()));
			$post->setContent($status->getStatus());
			$post->setType($status->getVisibility());

			if (!empty($status->getMediaIds())) {
				$post->setMedias(
					array_map(function (Document $document): MediaAttachment {
						return $document->convertToMediaAttachment($this->urlGenerator);
					}, $this->documentService->getMediaFromArray(
						$status->getMediaIds(),
						$this->viewer->getPreferredUsername()
					))
				);
			}

			$activity = $this->postService->createPost($post);

			$item = $this->streamService->getStreamById(
				$activity->getObjectId(),
				true,
				ACore::FORMAT_LOCAL
			);

			return new DataResponse($item, Http::STATUS_OK);
		} catch (Exception $e) {
			$this->logger->warning('issues while statusNew', ['exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}


	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @return DataResponse
	 */
	public function mediaNew(): DataResponse {
		try {
			$this->initViewer(true);

			$file = $_FILES['file'] ?? [];
			if (empty($file)) {
				throw new Exception('no media found');
			}

			if ($file['error'] !== UPLOAD_ERR_OK) {
				throw new Exception('error during upload');
			}

			$name = $file['tmp_name'] ?? '';
			$size = $file['size'] ?? -1;
			$type = $file['type'] ?? '';

			if ($name === '' || $size === -1 || $type === '') {
				throw new Exception('missing details');
			}

			$this->logger->debug('[ApiController] mediaNew: ' . json_encode($file));

			$document = new Document();
			$document->setAccount($this->viewer->getPreferredUsername());
			$document->setUrlCloud($this->configService->getCloudUrl());
			$document->generateUniqueId('/documents/local');
			$document->setPublic(true);

			$this->cacheDocumentService->saveFromTempToCache($document, $name);
			$service = AP::$activityPub->getInterfaceForItem($document);
			$service->save($document);

			$mediaAttachment = $document->convertToMediaAttachment($this->urlGenerator);

			$this->logger->debug('generated attachment: ' . json_encode($mediaAttachment));

			return new DataResponse($mediaAttachment, Http::STATUS_OK);
		} catch (Exception $e) {
			$this->logger->warning('issues while mediaNew', ['exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}


	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $id
	 *
	 * @return Response
	 */
	public function mediaGet(string $nid, string $preview = ''): Response {
		try {
			return new DataResponse([], Http::STATUS_OK);
		} catch (Exception $e) {
			$this->logger->warning('issues while mediaNew', ['exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $id
	 *
	 * @return Response
	 */
	public function mediaOpen(string $uuid): Response {
		$ext = '';
		if (strpos($uuid, '.') > 0) {
			[$uuid, $ext] = explode('.', $uuid, 2);
		}

		try {
			$mime = '';
			$file = $this->documentService->getFromUuid($uuid);

			return new FileDisplayResponse(
				$file, Http::STATUS_OK, ['Content-Type' => $this->mimeFromExt($ext)]
			);
		} catch (Exception $e) {
			$this->logger->warning('issues while mediaOpen', ['exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * @param string $ext
	 * only support image actually
	 *
	 * @return string
	 */
	private function mimeFromExt(string $ext): string {
		if ($ext === '') {
			return '';
		}

		return 'image/' . $ext;
	}

	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $timeline
	 * @param bool $local
	 * @param int $limit
	 * @param int $max_id
	 * @param int $min_id
	 * @param int $since_id
	 *
	 * @return DataResponse
	 */
	public function timelines(
		string $timeline,
		bool $local = false,
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since_id = 0
	): DataResponse {
		try {
			$this->initViewer(true);

			if (!in_array(
				strtolower($timeline),
				[
					ProbeOptions::HOME,
					ProbeOptions::ACCOUNT,
					ProbeOptions::PUBLIC,
					ProbeOptions::DIRECT,
					ProbeOptions::FAVOURITES
				]
			)) {
				throw new UnknownProbeException('unknown timeline');
			}

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe($timeline)
					->setLocal($local)
					->setLimit($limit)
					->setMaxId($max_id)
					->setMinId($min_id)
					->setSince($since_id);

			$posts = $this->streamService->getTimeline($options);

			return new DataResponse($posts, Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param int $nid
	 *
	 * @return DataResponse
	 */
	public function statusGet(int $nid): DataResponse {
		try {
			$this->initViewer(true);

			$item = $this->streamService->getStreamByNid($nid);

			return new DataResponse($item, Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param int $nid
	 *
	 * @return DataResponse
	 */
	public function statusContext(int $nid): DataResponse {
		try {
			$this->initViewer(true);
			$context = $this->streamService->getContextByNid($nid);

			return new DataResponse($context, Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}
	}

	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param int $nid
	 * @param string $action
	 *
	 * @return DataResponse
	 */
	public function statusAction(int $nid, string $act): DataResponse {
		try {
			$this->initViewer(true);
			$item = $this->actionService->action($this->viewer->getId(), $nid, $act);

			if ($item === null) {
				$item = $this->streamService->getStreamByNid($nid);
			}

			return new DataResponse($item, Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $account
	 * @param int $limit
	 * @param int $max_id
	 * @param int $min_id
	 * @param int $since
	 *
	 * @return DataResponse
	 */
	public function accountStatuses(
		string $account,
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since = 0
	): DataResponse {
		try {
			$this->initViewer(true);

			$local = $this->cacheActorService->getFromLocalAccount($account);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::ACCOUNT)
					->setAccountId($local->getId())
					->setLimit($limit)
					->setMaxId($max_id)
					->setMinId($min_id)
					->setSince($since);

			$posts = $this->streamService->getTimeline($options);

			return new DataResponse($posts, Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $account
	 *
	 * @return DataResponse
	 */
	public function accountFollowing(
		string $account,
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since = 0
	): DataResponse {
		try {
			$this->initViewer(true);
			$local = $this->cacheActorService->getFromLocalAccount($account);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::FOLLOWING)
					->setAccountId($local->getId())
					->setLimit($limit)
					->setMaxId($max_id)
					->setMinId($min_id)
					->setSince($since);

			return new DataResponse($this->cacheActorService->probeActors($options), Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $account
	 *
	 * @return DataResponse
	 */
	public function accountFollowers(
		string $account,
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since = 0
	): DataResponse {
		try {
			$this->initViewer(true);

			$local = $this->cacheActorService->getFromLocalAccount($account);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::FOLLOWERS)
					->setAccountId($local->getId())
					->setLimit($limit)
					->setMaxId($max_id)
					->setMinId($min_id)
					->setSince($since);

			return new DataResponse($this->cacheActorService->probeActors($options), Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param int $limit
	 * @param int $max_id
	 * @param int $min_id
	 * @param int $since_id
	 *
	 * @return DataResponse
	 */
	public function favourites(
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since_id = 0
	): DataResponse {
		try {
			$this->initViewer(true);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::FAVOURITES)
					->setLimit($limit)
					->setMaxId($max_id)
					->setMinId($min_id)
					->setSince($since_id);

			$posts = $this->streamService->getTimeline($options);

			return new DataResponse($posts, Http::STATUS_OK);
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
	public function notifications(
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since_id = 0,
		array $types = [],
		array $exclude_types = [],
		string $accountId = ''
	): DataResponse {
		try {
			$this->initViewer(true);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::NOTIFICATIONS)
					->setLimit($limit)
					->setMaxId($max_id)
					->setMinId($min_id)
					->setSince($since_id)
					->setTypes($types)
					->setExcludeTypes($exclude_types)
					->setAccountId($accountId);

			$posts = $this->streamService->getTimeline($options);

			return new DataResponse($posts, Http::STATUS_OK);
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
	public function tag(
		string $hashtag,
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since_id = 0,
		bool $local = false,
		bool $only_media = false
	): DataResponse {
		try {
			$this->initViewer(true);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe('hashtag')
					->setLimit($limit)
					->setMaxId($max_id)
					->setMinId($min_id)
					->setSince($since_id)
					->setLocal($local)
					->setOnlyMedia($only_media)
					->setArgument($hashtag);

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

			$this->logger->debug(
				'[ApiController] initViewer: ' . $userId . ' (bearer=' . $this->bearer . ')'
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


	private function convertInput(string $input): array {
		$contentType = $this->request->getHeader('Content-Type');

		$pos = strpos($contentType, ';');
		if ($pos > 0) {
			$contentType = substr($contentType, 0, $pos);
		}

		switch ($contentType) {
			case 'application/json':
				return json_decode($input, true);

			case 'application/x-www-form-urlencoded':
				return $this->request->getParams();

			default: // in case of no header ...
				$result = json_decode($input, true);
				if (is_array($result)) {
					return $result;
				}

				return $this->request->getParams();
		}
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
