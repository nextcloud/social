<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use Gumlet\ImageResize;
use Gumlet\ImageResizeException;
use OCA\Social\Exceptions\CacheContentException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Model\NCRequest;
use OCA\Social\Tools\Model\Request;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TStringTools;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;

class CacheDocumentService {
	use TArrayTools;
	use TStringTools;

	public const RESIZED_WIDTH = 800;
	public const RESIZED_HEIGHT = 800;

	private IAppData $appData;
	private CurlService $curlService;
	private ConfigService $configService;
	private BlurService $blurService;

	public function __construct(
		IAppData $appData,
		CurlService $curlService,
		BlurService $blurService,
		ConfigService $configService
	) {
		$this->appData = $appData;
		$this->curlService = $curlService;
		$this->blurService = $blurService;
		$this->configService = $configService;
	}


	/**
	 * @brief Save the local upload to the cache
	 *
	 * @throws CacheContentMimeTypeException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function saveLocalUploadToCache(Document $document, string $uploaded, string &$mime = '') {
		$content = $uploaded;

		$this->saveContentToCache($document, $content, $mime);
	}


	/**
	 * @param Document $document
	 * @param string $mime
	 *
	 * @throws CacheContentMimeTypeException
	 * @throws MalformedArrayException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function saveRemoteFileToCache(Document $document, string &$mime = '') {
		$content = $this->retrieveContent($document->getUrl());

		$this->saveContentToCache($document, $content, $mime);
	}


	/**
	 * @param Document $document
	 * @param string $content
	 * @param string $mime
	 *
	 * @throws CacheContentMimeTypeException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function saveContentToCache(Document $document, string $content, string &$mime = '') {
		// To get the mime type, we create a temp file
		$tmpFile = tmpfile();
		$tmpPath = stream_get_meta_data($tmpFile)['uri'];
		fwrite($tmpFile, $content);
		$mime = mime_content_type($tmpPath);
		fclose($tmpFile);

		$this->filterMimeTypes($mime);

		$filename = $this->generateFileFromContent($content);
		$document->setLocalCopy($filename);

		$this->resizeImage($document, $content);
		$resized = $this->generateFileFromContent($content);
		$document->setResizedCopy($resized);
	}

	public function saveFromTempToCache(Document $document, string $tmpPath) {
		$mime = mime_content_type($tmpPath);

		$this->filterMimeTypes($mime);

		$document->setMediaType($mime);
		$document->setMimeType($mime);

		$file = fopen($tmpPath, 'r');
		$content = fread($file, filesize($tmpPath));

		$filename = $this->generateFileFromContent($content);
		$document->setLocalCopy($filename);

		$this->resizeImage($document, $content);
		$resized = $this->generateFileFromContent($content);
		$document->setResizedCopy($resized);
	}


	/**
	 * @param string $content
	 *
	 * @return string
	 * @throws NotPermittedException
	 * @throws NotFoundException
	 */
	private function generateFileFromContent(string $content): string {
		$filename = $this->uuid();
		$path = $this->generatePath($filename);

		try {
			$folder = $this->appData->getFolder($path);
		} catch (NotFoundException $e) {
			$folder = $this->appData->newFolder($path);
		}

		$cache = $folder->newFile($filename);
		$cache->putContent($content);

		return $filename;
	}


	/**
	 * creating a path aa/bb/cc/dd/ from the filename aabbccdd-0123-[...]
	 *
	 * @param string $filename
	 *
	 * @return string
	 */
	private function generatePath(string $filename): string {
		return chunk_split(substr($filename, 0, 8), 2, '/');
	}


	/**
	 *
	 * @param string $mime
	 *
	 * @throws CacheContentMimeTypeException
	 */
	public function filterMimeTypes(string $mime) {
		$allowedMimeType = [
			'image/jpeg',
			'image/gif',
			'image/png'
		];

		if (in_array($mime, $allowedMimeType)) {
			return;
		}

		throw new CacheContentMimeTypeException();
	}


	/**
	 * @param string $content
	 */
	private function resizeImage(Document $document, string &$content): void {
		try {
			$image = ImageResize::createFromString($content);
			$image->quality_jpg = 80;
			$image->quality_png = 7;

			$image->resizeToBestFit(self::RESIZED_WIDTH, self::RESIZED_HEIGHT);
			$newContent = $image->getImageAsString();

			if ($newContent) {
				$content = $newContent;
			}
		} catch (ImageResizeException $e) {
		}

		$document->setLocalCopySize($image->getSourceWidth(), $image->getSourceHeight());
		$document->setResizedCopySize($image->getDestWidth(), $image->getDestHeight());

		$hash = $this->blurService->generateBlurHash(imagecreatefromstring($content));
		$document->setBlurHash($hash);
	}


	/**
	 * @param string $filename
	 *
	 * @return ISimpleFile
	 * @throws CacheContentException
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getContentFromCache(string $filename): ISimpleFile {
		if ($filename === '') {
			throw new CacheDocumentDoesNotExistException();
		}

		// right now, we do not handle cache for local avatar, we need to change this
		// so the current avatar is cached, or a new avatar is uploaded
		if ($filename === 'avatar') {
			throw new CacheContentException();
		}

		try {
			return $this->appData->getFolder($this->generatePath($filename))
								 ->getFile($filename);
		} catch (Exception $e) {
			throw new CacheContentException();
		}
	}


	public function getFromUuid(string $uuid): ISimpleFile {
		try {
			return $this->appData->getFolder($this->generatePath($uuid))
								 ->getFile($uuid);
		} catch (NotFoundException $e) {
			throw new NotFoundException('document not found');
		}
	}

	/**
	 * @param string $url
	 *
	 * @return string
	 * @throws MalformedArrayException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function retrieveContent(string $url): string {
		$url = parse_url($url);
		$this->mustContains(['path', 'host', 'scheme'], $url);
		$request = new NCRequest($url['path'], Request::TYPE_GET, true);
		$request->setHost($url['host']);
		$request->setProtocol($url['scheme']);

		return $this->curlService->doRequest($request);
	}
}
