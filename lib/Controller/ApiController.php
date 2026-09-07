<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AP;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnknownProbeException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\Post;
use OCA\Social\Model\Report;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActionService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\RelationshipService;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Traits\TNCDataResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\NotFoundException;
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
	private RelationshipService $relationshipService;
	private StreamService $streamService;
	private ActionService $actionService;
	private PostService $postService;
	private ReportService $reportService;
	private ConfigService $configService;
	private CurlService $curlService;

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
		RelationshipService $relationshipService,
		StreamService $streamService,
		ActionService $actionService,
		PostService $postService,
		ReportService $reportService,
		ConfigService $configService,
		CurlService $curlService,
	) {
		parent::__construct(Application::APP_ID, $request);

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
		$this->relationshipService = $relationshipService;
		$this->streamService = $streamService;
		$this->actionService = $actionService;
		$this->postService = $postService;
		$this->reportService = $reportService;
		$this->configService = $configService;
		$this->curlService = $curlService;

		$authHeader = trim($this->request->getHeader('Authorization'));
		if (strpos($authHeader, ' ')) {
			[$authType, $authToken] = explode(' ', $authHeader);
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
	 * Minimal Mastodon-style profile update: only `locked` (manually approve
	 * followers) is supported for now. Returns the updated account entity.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function updateCredentials(): DataResponse {
		try {
			$this->initViewer(true);

			$input = $this->convertInput(file_get_contents('php://input'));
			if (array_key_exists('locked', $input)) {
				$locked = in_array($input['locked'], [true, 1, '1', 'true'], true);
				$this->accountService->setLocked($this->currentSession(), $locked);

				// refresh the viewer so the returned entity carries the change
				$this->viewer = $this->cacheActorService->getFromLocalAccount(
					$this->viewer->getPreferredUsername()
				);
				$this->viewer->setExportFormat(ACore::FORMAT_LOCAL);
			}

			return new DataResponse($this->viewer, Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}
	}


	/**
	 * The accounts waiting for the viewer's approval to follow them.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function followRequests(): DataResponse {
		try {
			$this->initViewer(true);

			$accounts = $this->followService->getPendingRequests();
			foreach ($accounts as $account) {
				$account->setExportFormat(ACore::FORMAT_LOCAL);
			}

			return new DataResponse($accounts, Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function followRequestAuthorize(string $id): DataResponse {
		return $this->followRequestAction($id, true);
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function followRequestReject(string $id): DataResponse {
		return $this->followRequestAction($id, false);
	}


	private function followRequestAction(string $id, bool $authorize): DataResponse {
		try {
			$this->initViewer(true);
			$follower = $this->resolveTargetAccount($id);

			if ($authorize) {
				$this->followService->authorizeFollowRequest($follower);
			} else {
				$this->followService->rejectFollowRequest($follower);
			}

			return new DataResponse(
				$this->followService->getRelationshipWith($follower), Http::STATUS_OK
			);
		} catch (FollowNotFoundException $e) {
			return new DataResponse(['error' => 'no pending follow request'], Http::STATUS_NOT_FOUND);
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}
	}


	/**
	 * Files a moderation report about an account (and optionally some of its
	 * statuses) for the instance admins.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function reportNew(): DataResponse {
		try {
			$this->initViewer(true);

			$input = $this->convertInput(file_get_contents('php://input'));
			$accountId = (string)($input['account_id'] ?? '');
			if ($accountId === '') {
				return new DataResponse(['error' => 'account_id is required'], Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			$target = $this->resolveTargetAccount($accountId);
			if ($target->getId() === $this->viewer->getId()) {
				return new DataResponse(['error' => 'you cannot report yourself'], Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			$statusIds = $input['status_ids'] ?? [];
			if (!is_array($statusIds)) {
				$statusIds = [$statusIds];
			}
			$statusIds = array_map('strval', $statusIds);

			$report = $this->reportService->reportFromLocal(
				$this->viewer,
				$target,
				$statusIds,
				(string)($input['comment'] ?? ''),
				(string)($input['category'] ?? Report::CATEGORY_OTHER)
			);
			$target->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($report, Http::STATUS_OK);
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

			// Use the viewer that was already initialized
			$actor = $this->accountService->getActorFromUserId($this->currentSession(), true);
			$post = new Post($actor);
			$post->setContent(nl2br($status->getStatus()));
			$post->setType($status->getVisibility());

			if (!empty($status->getMediaIds())) {
				$post->setMedias(
					array_map(function (Document $document): MediaAttachment {
						return $document->convertToMediaAttachment(
							$this->urlGenerator,
							ACore::FORMAT_ACTIVITYPUB
						);
					}, $this->documentService->getMediaFromArray(
						$status->getMediaIds(),
						$this->viewer->getPreferredUsername()
					))
				);
			}

			if ($status->getInReplyToId() > 0) {
				try {
					$replyTo = $this->streamService->getStreamByNid($status->getInReplyToId());
					$post->setReplyTo($replyTo->getId());
				} catch (StreamNotFoundException $e) {
					$this->logger->debug('reply to post not found');
				}
			}

			$activity = $this->postService->createPost($post);

			$item = $this->streamService->getStreamById(
				$activity->getObjectId(),
				true,
				ACore::FORMAT_LOCAL
			);

			$this->logger->info('[ApiController] Status created successfully', [
				'postId' => $activity->getObjectId()
			]);

			return new DataResponse($item, Http::STATUS_OK);
		} catch (Exception $e) {
			$this->logger->error('[ApiController] statusNew failed', [
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}


	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param int $nid
	 *
	 * @return DataResponse
	 */
	public function statusUpdate(int $nid): DataResponse {
		try {
			$this->initViewer(true);

			$input = file_get_contents('php://input');
			$status = new Status();
			$status->import($this->convertInput($input));

			$actor = $this->accountService->getActorFromUserId($this->currentSession(), true);

			$item = $this->postService->editPost(
				$nid,
				$actor,
				nl2br($status->getStatus()),
				$status->getSpoilerText() !== '' ? $status->getSpoilerText() : null,
				$status->isSensitive()
			);
			$item->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($item, Http::STATUS_OK);
		} catch (Exception $e) {
			$this->logger->error('[ApiController] statusUpdate failed', [
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
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
			$document->setLocal(true);
			$document->setAccount($this->viewer->getPreferredUsername());
			$document->setUrlCloud($this->configService->getCloudUrl());
			$document->generateUniqueId('/documents/local');
			$document->setPublic(true);
			// the alt text; `focus` is accepted but not stored (no focal-point support)
			$document->setDescription((string)$this->request->getParam('description', ''));

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
	 * Same upload as mediaNew — modern Mastodon clients POST /api/v2/media and
	 * only fall back to v1 on a 404.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function mediaNewV2(): DataResponse {
		return $this->mediaNew();
	}


	/**
	 * One of the viewer's own attachments, by the id mediaNew returned.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function mediaGet(string $nid, string $preview = ''): Response {
		try {
			$this->initViewer(true);

			return new DataResponse($this->ownAttachment($nid), Http::STATUS_OK);
		} catch (NotFoundException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Exception $e) {
			$this->logger->warning('issues while mediaGet', ['exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNAUTHORIZED);
		}
	}


	/**
	 * Updates the alt text of the viewer's own attachment.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function mediaUpdate(string $nid): Response {
		try {
			$this->initViewer(true);

			$document = $this->ownDocument($nid);
			$input = $this->convertInput(file_get_contents('php://input'));
			if (array_key_exists('description', $input)) {
				$document->setDescription((string)$input['description']);
				$this->documentService->updateDescription($document);
			}

			return new DataResponse(
				$document->convertToMediaAttachment($this->urlGenerator), Http::STATUS_OK
			);
		} catch (NotFoundException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Exception $e) {
			$this->logger->warning('issues while mediaUpdate', ['exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNAUTHORIZED);
		}
	}


	/**
	 * @throws NotFoundException when the id is unknown or belongs to someone else
	 */
	private function ownDocument(string $nid): Document {
		$documents = $this->documentService->getMediaFromArray(
			[$nid], $this->viewer->getPreferredUsername()
		);
		if (count($documents) !== 1) {
			throw new NotFoundException('unknown media');
		}

		return $documents[0];
	}

	/**
	 * @throws NotFoundException
	 */
	private function ownAttachment(string $nid): MediaAttachment {
		return $this->ownDocument($nid)->convertToMediaAttachment($this->urlGenerator);
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
		if (strpos($uuid, '.') > 0) {
			[$uuid] = explode('.', $uuid, 2);
		}

		try {
			// Only public copies are served here: this route is unauthenticated, so a
			// non-public document would otherwise be readable by anyone with the uuid.
			[$file, $document] = $this->documentService->getFromUuid($uuid, true);

			// The stored media type was sniffed from the content at ingest; the
			// extension in the URL is whatever the requester chose to write there.
			return new FileDisplayResponse(
				$file, Http::STATUS_OK, ['Content-Type' => $document->getMediaType()]
			);
		} catch (NotFoundException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Exception $e) {
			$this->logger->warning('issues while mediaOpen', ['exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
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
		int $since_id = 0,
	): DataResponse {
		$this->logger->info('[ApiController] timelines called', [
			'timeline' => $timeline,
			'local' => $local,
			'limit' => $limit,
			'max_id' => $max_id,
			'min_id' => $min_id,
			'since_id' => $since_id
		]);
		try {
			$this->initViewer(true);
			$this->logger->debug('[ApiController] Viewer initialized', [
				'viewerId' => $this->viewer?->getId()
			]);

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
				$this->logger->error('[ApiController] Unknown timeline requested', [
					'timeline' => $timeline
				]);
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
			$this->logger->info('[ApiController] Timeline retrieved', [
				'timeline' => $timeline,
				'postsCount' => count($posts)
			]);

			return new DataResponse($posts, Http::STATUS_OK);
		} catch (Exception $e) {
			$this->logger->error('[ApiController] Timeline request failed', [
				'timeline' => $timeline,
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
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
			$this->initViewer(false);

			$item = $this->streamService->getStreamByNid($nid);
			$item->setExportFormat(ACore::FORMAT_LOCAL);

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
			$this->initViewer(false);
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
			$actor = $this->accountService->getActor($this->viewer->getPreferredUsername());
			$item = $this->actionService->action($actor, $nid, $act);

			if ($item === null) {
				$item = $this->streamService->getStreamByNid($nid);
			}

			$item->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($item, Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}
	}


	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function accountBlock(string $id): DataResponse {
		return $this->relationshipAction($id, 'block');
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function accountUnblock(string $id): DataResponse {
		return $this->relationshipAction($id, 'unblock');
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function accountMute(string $id, bool $notifications = true): DataResponse {
		return $this->relationshipAction($id, 'mute', $notifications);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function accountUnmute(string $id): DataResponse {
		return $this->relationshipAction($id, 'unmute');
	}

	private function relationshipAction(string $id, string $action, bool $notifications = true): DataResponse {
		try {
			$this->initViewer(true);
			$target = $this->resolveTargetAccount($id);

			switch ($action) {
				case 'block':
					$this->relationshipService->block($this->viewer, $target);
					break;
				case 'unblock':
					$this->relationshipService->unblock($this->viewer, $target);
					break;
				case 'mute':
					$this->relationshipService->mute($this->viewer, $target, $notifications);
					break;
				case 'unmute':
					$this->relationshipService->unmute($this->viewer, $target);
					break;
			}

			// Mastodon clients expect the updated relationship entity back
			$this->followService->setViewer($this->viewer);

			return new DataResponse(
				$this->followService->getRelationshipWith($target), Http::STATUS_OK
			);
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function blocks(int $limit = 40): DataResponse {
		return $this->listRelatedAccounts(ActorRelation::TYPE_BLOCK, $limit);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function mutes(int $limit = 40): DataResponse {
		return $this->listRelatedAccounts(ActorRelation::TYPE_MUTE, $limit);
	}

	private function listRelatedAccounts(string $type, int $limit): DataResponse {
		try {
			$this->initViewer(true);
			$limit = max(1, min(ProbeOptions::MAX_LIMIT, $limit));

			$accounts = [];
			foreach ($this->relationshipService->getRelated($this->viewer, $type, $limit) as $person) {
				$person->setExportFormat(ACore::FORMAT_LOCAL);
				$accounts[] = $person;
			}

			return new DataResponse($accounts, Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->error($e->getMessage());
		}
	}

	/**
	 * Resolve a Mastodon-style account reference: the numeric id every API entity
	 * carries, or a full actor id.
	 *
	 * @throws Exception
	 */
	private function resolveTargetAccount(string $id): Person {
		if (is_numeric($id) && (int)$id > 0) {
			$actors = $this->cacheActorService->getFromNids([(int)$id]);
			if ($actors !== []) {
				return $actors[0];
			}
		}

		return $this->cacheActorService->getFromId($id);
	}

	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param array $id
	 *
	 * @return DataResponse
	 */
	public function relationships(array $id): DataResponse {
		try {
			$this->initViewer(true);

			return new DataResponse($this->followService->getRelationships($id), Http::STATUS_OK);
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
		int $since_id = 0,
	): DataResponse {
		try {
			$this->initViewer(false);

			$local = $this->cacheActorService->getFromAccount($account);
			$this->streamService->syncRemoteTimeline($local);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::ACCOUNT)
				->setAccountId($local->getId())
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
	 * @param string $account
	 *
	 * @return DataResponse
	 */
	public function accountFollowing(
		string $account,
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since = 0,
	): DataResponse {
		try {
			$this->initViewer(false);
			$actor = $this->cacheActorService->getFromAccount($account);

			$parts = explode('@', $account);
			$domain = end($parts);
			$cloudHost = $this->configService->getCloudHost();
			$socialAddress = $this->configService->getSocialAddress();
			if ($domain !== '' && $domain !== $cloudHost && $domain !== $socialAddress) {
				$followingUrl = $actor->getFollowing();
				if (!empty($followingUrl)) {
					$result = $this->fetchRemoteCollection($followingUrl, $limit);
					return new DataResponse($result, Http::STATUS_OK);
				}
			}

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::FOLLOWING)
				->setAccountId($actor->getId())
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
		int $since = 0,
	): DataResponse {
		try {
			$this->initViewer(false);

			$actor = $this->cacheActorService->getFromAccount($account);

			$parts = explode('@', $account);
			$domain = end($parts);
			$cloudHost = $this->configService->getCloudHost();
			$socialAddress = $this->configService->getSocialAddress();
			if ($domain !== '' && $domain !== $cloudHost && $domain !== $socialAddress) {
				$followersUrl = $actor->getFollowers();
				if (!empty($followersUrl)) {
					$result = $this->fetchRemoteCollection($followersUrl, $limit);
					return new DataResponse($result, Http::STATUS_OK);
				}
			}

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::FOLLOWERS)
				->setAccountId($actor->getId())
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
		int $since_id = 0,
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
	 */
	public function bookmarks(
		int $limit = 20,
		int $max_id = 0,
		int $min_id = 0,
		int $since_id = 0,
	): DataResponse {
		try {
			$this->initViewer(true);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::BOOKMARKS)
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
		string $accountId = '',
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
		bool $only_media = false,
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
	 * @param string $url
	 * @param int $limit
	 *
	 * @return array
	 */
	private function fetchRemoteCollection(string $url, int $limit = 20): array {
		// A remote page can carry far more entries than asked for, and each
		// unresolved id below is a remote fetch, so both the limit and the number of
		// entries walked are bounded — an anonymous caller must not be able to turn
		// one request into thousands of outbound fetches.
		$limit = max(1, min(ProbeOptions::MAX_LIMIT, $limit));

		try {
			$collectionData = $this->curlService->retrieveObject($url);
		} catch (Exception $e) {
			return [];
		}

		$pageData = $collectionData;
		if (isset($collectionData['first'])) {
			$pageUrl = is_array($collectionData['first'])
				? ($collectionData['first']['id'] ?? '')
				: $collectionData['first'];
			if (!empty($pageUrl) && is_string($pageUrl)) {
				try {
					$pageData = $this->curlService->retrieveObject($pageUrl);
				} catch (Exception $e) {
					return [];
				}
			} elseif (is_array($collectionData['first'])) {
				$pageData = $collectionData['first'];
			}
		}

		$items = $pageData['orderedItems'] ?? $pageData['items'] ?? [];
		if (!is_array($items)) {
			return [];
		}

		$actors = [];
		$count = 0;
		// array_slice bounds the walk itself: the $count guard alone only limits
		// successes, so a page of unresolvable ids would still be fetched one by one.
		foreach (array_slice($items, 0, $limit) as $item) {
			if ($count >= $limit) {
				break;
			}

			if (is_array($item) && isset($item['id'])) {
				try {
					$person = AP::$activityPub->getItemFromData($item);
					if (AP::$activityPub->isActor($person)) {
						$person->setExportFormat(ACore::FORMAT_LOCAL);
						$actors[] = $person;
						$count++;
					}
				} catch (Exception $e) {
					continue;
				}
				continue;
			}

			$actorId = is_string($item) ? $item : '';
			if ($actorId === '') {
				continue;
			}

			try {
				$person = $this->cacheActorService->getFromId($actorId);
				$person->setExportFormat(ACore::FORMAT_LOCAL);
				$actors[] = $person;
				$count++;
			} catch (Exception $e) {
				continue;
			}
		}

		return $actors;
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

			$this->logger->debug('[ApiController] initViewer: ' . $userId);

			// Get or create the actor
			$account = $this->accountService->getActorFromUserId($userId, true);
			$this->logger->debug('[ApiController] Actor retrieved/created', [
				'userId' => $userId,
				'username' => $account->getPreferredUsername()
			]);

			// Try to get from cache, if it fails, cache it first
			try {
				$this->viewer = $this->cacheActorService->getFromLocalAccount($account->getPreferredUsername());
			} catch (Exception $e) {
				$this->logger->warning('[ApiController] Actor not in cache, caching now', [
					'username' => $account->getPreferredUsername(),
					'exception' => $e->getMessage()
				]);
				// Cache the actor and retry
				$this->accountService->cacheLocalActorByUsername($account->getPreferredUsername());
				$this->viewer = $this->cacheActorService->getFromLocalAccount($account->getPreferredUsername());
			}

			$this->viewer->setExportFormat(ACore::FORMAT_LOCAL);

			$this->streamService->setViewer($this->viewer);
			$this->followService->setViewer($this->viewer);
			$this->cacheActorService->setViewer($this->viewer);

			$this->logger->info('[ApiController] Viewer initialized successfully', [
				'viewerId' => $this->viewer->getId()
			]);

			return true;
		} catch (InsufficientScopeException $e) {
			// the token is fine, its grant is not — tell the client which scope it lacks
			if ($exception) {
				throw $e;
			}
		} catch (Exception $e) {
			$this->logger->error('[ApiController] initViewer failed', [
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
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
	/**
	 * A bearer token wins over the session cookie: an OAuth client stays inside
	 * the scopes it was granted even when the browser also carries a session.
	 * The cookie is only accepted together with a valid CSRF token — these
	 * routes are @NoCSRFRequired so that external clients (which cannot obtain
	 * one) work, and without this check a cross-site form POST would act as the
	 * logged-in user.
	 */
	private function currentSession(): string {
		if ($this->bearer !== '') {
			$this->client = $this->clientService->getFromToken($this->bearer);
			$this->checkTokenScope();

			return $this->client->getAuthUserId();
		}

		$user = $this->userSession->getUser();
		if ($user !== null && $this->request->passesCSRFCheck()) {
			return $user->getUID();
		}

		throw new AccountDoesNotExistException('userId not defined');
	}


	/**
	 * The scope a bearer token needs for the current route. Everything defaults
	 * to 'read'; the state-changing routes are enumerated. A scope is satisfied
	 * by itself or any of its granular variants ('write' by 'write:statuses').
	 *
	 * @throws ClientNotFoundException
	 */
	private function checkTokenScope(): void {
		$route = $this->request->getParam('_route', '');
		$name = substr((string)$route, strrpos((string)$route, '.') + 1);

		$accepted = match ($name) {
			'statusNew', 'statusUpdate', 'mediaNew', 'mediaNewV2', 'mediaUpdate', 'statusAction',
			'updateCredentials', 'reportNew' => ['write'],
			'accountBlock', 'accountUnblock', 'accountMute', 'accountUnmute',
			'followRequestAuthorize', 'followRequestReject' => ['follow', 'write'],
			'appsCredentials' => [],
			default => ['read'],
		};

		foreach ($accepted as $scope) {
			foreach ($this->client->getAuthScopes() as $granted) {
				if ($granted === $scope || str_starts_with($granted, $scope . ':')) {
					return;
				}
			}
		}

		if ($accepted !== []) {
			throw new InsufficientScopeException(
				'token scope does not allow this request (needs ' . implode(' or ', $accepted) . ')'
			);
		}
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
