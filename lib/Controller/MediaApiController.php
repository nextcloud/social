<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Controller;

use OCA\Social\Entity\MediaAttachment;
use OCA\Social\Service\AccountFinder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\DB\ORM\IEntityManager;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\AppFramework\Controller;
use OCP\Files\IMimeTypeDetector;
use OCP\Image;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

class MediaApiController extends Controller {

	private IL10N $l10n;
	private IMimeTypeDetector $mimeTypeDetector;
	private IAppData $appData;
	private IUserSession $userSession;
	private AccountFinder $accountFinder;
	private IEntityManager $entityManager;
	private IURLGenerator $generator;
	private LoggerInterface $logger;

	public const IMAGE_MIME_TYPES = [
		'image/png',
		'image/jpeg',
		'image/jpg',
		'image/gif',
		'image/x-xbitmap',
		'image/x-ms-bmp',
		'image/bmp',
		'image/svg+xml',
		'image/webp',
	];

	public function __construct(
		string $appName,
		IRequest $request,
		IL10N $l10n,
		IMimeTypeDetector $mimeTypeDetector,
		IAppData $appData,
		IUserSession $userSession,
		AccountFinder $accountFinder,
		IEntityManager $entityManager,
		IURLGenerator $generator,
		LoggerInterface $logger
	) {
		parent::__construct($appName, $request);
		$this->l10n = $l10n;
		$this->mimeTypeDetector = $mimeTypeDetector;
		$this->appData = $appData;
		$this->userSession = $userSession;
		$this->accountFinder = $accountFinder;
		$this->entityManager = $entityManager;
		$this->generator = $generator;
		$this->logger = $logger;
	}

	/**
	 * Creates an attachment to be used with a new status.
	 *
	 * @NoAdminRequired
	 */
	public function uploadMedia(?string $description, ?string $focus = ''): DataResponse {
		try {
			$file = $this->getUploadedFile('file');
			if (!isset($file['tmp_name'], $file['name'], $file['type'])) {
				return new DataResponse(['error' => 'No uploaded file'], Http::STATUS_BAD_REQUEST);
			}

			if (!in_array($file['type'], MediaAttachment::IMAGE_MIME_TYPES, true)) {
				return new DataResponse(['error' => 'Image type not supported'], Http::STATUS_BAD_REQUEST);
			}

			$account = $this->accountFinder->getCurrentAccount($this->userSession->getUser());

			$meta = [];
			$this->processFocus($focus, $meta);

			$newFileResource = fopen($file['tmp_name'], 'rb');
			if (!is_resource($newFileResource)) {
				return new DataResponse(['error' => 'Image type not supported'], Http::STATUS_BAD_REQUEST);
			}

			$image = new Image();
			$image->loadFromFileHandle($newFileResource);
			$meta['original'] = [
				"width" => $image->width(),
				"height" => $image->height(),
				"size" => $image->width() . "x" . $image->height(),
				"aspect" => $image->width() /  $image->height(),
			];

			$attachment = MediaAttachment::create();
			$attachment->setMimetype($file['type']);
			$attachment->setAccount($account);
			$attachment->setDescription($description ?? '');
			$attachment->setMeta($meta);
			$this->entityManager->persist($attachment);
			$this->entityManager->flush();

			try {
				$folder = $this->appData->getFolder('media-attachments');
			} catch (NotFoundException $e) {
				$folder = $this->appData->newFolder('media-attachments');
			}
			assert($attachment->getId() !== '');
			$folder->newFile($attachment->getId(), $image->data());

			return new DataResponse($attachment->toMastodonApi($this->generator));
		} catch (\Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new DataResponse([
				  "error" => "Validation failed: File content type is invalid, File is invalid",
			], 500);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function updateMedia(string $id, ?string $description, ?string $focus = ''): Response {
		try {
			$account = $this->accountFinder->getCurrentAccount($this->userSession->getUser());
			$attachementRepository = $this->entityManager->getRepository(MediaAttachment::class);
			$attachement = $attachementRepository->findOneBy([
				'id' => $id,
			]);
			if ($attachement->getAccount()->getId() !== $account->getId()) {
				throw new NotFoundResponse();
			}

			$attachement->setDescription($description ?? '');
			$this->entityManager->persist($attachement);
			$this->entityManager->flush();

			return new DataResponse($attachement->toMastodonApi($this->generator));
		} catch (\Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new DataResponse([
				  "error" => "Validation failed: File content type is invalid, File is invalid",
			], 500);
		}
	}

	private function processFocus(string $focus, array &$meta): void {
		if ($focus === '') {
			return;
		}

		try {
			[$x, $y] = explode(',', $focus);
			$meta['focus'] = ['x' => $x, 'y' => $y];
		} catch (\Exception $e) {
			return;
		}
	}

	private function getUploadedFile(string $key): array {
		$file = $this->request->getUploadedFile($key);
		$error = null;
		$phpFileUploadErrors = [
			UPLOAD_ERR_OK => $this->l10n->t('The file was uploaded'),
			UPLOAD_ERR_INI_SIZE => $this->l10n->t('The uploaded file exceeds the upload_max_filesize directive in php.ini'),
			UPLOAD_ERR_FORM_SIZE => $this->l10n->t('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form'),
			UPLOAD_ERR_PARTIAL => $this->l10n->t('The file was only partially uploaded'),
			UPLOAD_ERR_NO_FILE => $this->l10n->t('No file was uploaded'),
			UPLOAD_ERR_NO_TMP_DIR => $this->l10n->t('Missing a temporary folder'),
			UPLOAD_ERR_CANT_WRITE => $this->l10n->t('Could not write file to disk'),
			UPLOAD_ERR_EXTENSION => $this->l10n->t('A PHP extension stopped the file upload'),
		];

		if (empty($file)) {
			$error = $this->l10n->t('No file uploaded or file size exceeds maximum of %s', [Util::humanFileSize(Util::uploadLimit())]);
		}
		if (!empty($file) && array_key_exists('error', $file) && $file['error'] !== UPLOAD_ERR_OK) {
			$error = $phpFileUploadErrors[$file['error']];
		}
		if ($error !== null) {
			throw new \Exception($error);
		}
		return $file;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getMedia(string $shortcode, string $extension): DataDownloadResponse {
		try {
			$folder = $this->appData->getFolder('media-attachments');
		} catch (NotFoundException $e) {
			$folder = $this->appData->newFolder('media-attachments');
		}
		$attachementRepository = $this->entityManager->getRepository(MediaAttachment::class);
		$attachement = $attachementRepository->findOneBy([
			'shortcode' => $shortcode,
		]);
		$file = $folder->getFile($attachement->getId());
		return new DataDownloadResponse(
			$file->getContent(),
			(string) Http::STATUS_OK,
			$this->getSecureMimeType($file->getMimeType())
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function deleteMedia(string $id): DataResponse {
		try {
			$folder = $this->appData->getFolder('media-attachments');
		} catch (NotFoundException $e) {
			$folder = $this->appData->newFolder('media-attachments');
		}
		$attachementRepository = $this->entityManager->getRepository(MediaAttachment::class);
		$attachement = $attachementRepository->findOneBy([
			'id' => $id,
		]);
		$file = $folder->getFile($attachement->getId());
		$file->delete();
		$this->entityManager->remove($attachement);
		$this->entityManager->flush();
		return new DataResponse(['removed']);
	}

	/**
	 * Allow all supported mimetypes
	 * Use mimetype detector for the other ones
	 *
	 * @param string $mimetype
	 * @return string
	 */
	private function getSecureMimeType(string $mimetype): string {
		if (in_array($mimetype, self::IMAGE_MIME_TYPES)) {
			return $mimetype;
		}
		return $this->mimeTypeDetector->getSecureMimeType($mimetype);
	}
}
