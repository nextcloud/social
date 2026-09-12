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
use OCA\Social\Exceptions\CacheContentDecodeException;
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
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TStringTools;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use Throwable;

class CacheDocumentService {
	use TArrayTools;
	use TStringTools;

	public const RESIZED_WIDTH = 800;
	public const RESIZED_HEIGHT = 800;

	/**
	 * Ceiling on the pixel count of an image this app will decode.
	 *
	 * A decoded image costs roughly four bytes a pixel in GD, so the size of
	 * the file says nothing about the memory the decode needs: a 400 KB
	 * 30000x30000 PNG wants about 3.6 GB. The dimensions are read from the
	 * header before any decode happens, and anything past this is refused.
	 */
	public const MAX_PIXELS = 50000000; // 50 MP, ~200 MB decoded

	public function __construct(
		private IAppData $appData,
		private CurlService $curlService,
		private BlurService $blurService,
		private ConfigService $configService,
		private ImageConversionService $imageConversionService,
	) {
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
	 * @throws CacheContentDecodeException
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
	 * @throws CacheContentDecodeException
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

		if (str_starts_with((string)$mime, 'image/')) {
			$this->resizeImage($document, $content);
			$resized = $this->generateFileFromContent($content);
			$document->setResizedCopy($resized);
		}
	}

	/**
	 * @throws CacheContentDecodeException
	 * @throws CacheContentMimeTypeException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function saveFromTempToCache(Document $document, string $tmpPath) {
		$mime = mime_content_type($tmpPath);

		$this->filterMimeTypes($mime);

		$file = fopen($tmpPath, 'r');
		$content = fread($file, filesize($tmpPath));

		// Before anything is written: the camera's metadata comes off, and a
		// format no browser can draw becomes one it can. Both can change the
		// mime, so the document is told afterwards rather than before.
		[$content, $mime] = $this->imageConversionService->prepareForStorage($content, $mime);

		$document->setMediaType($mime);
		$document->setMimeType($mime);

		$filename = $this->generateFileFromContent($content);
		$document->setLocalCopy($filename);

		if (str_starts_with($mime, 'image/')) {
			$this->resizeImage($document, $content);
			$resized = $this->generateFileFromContent($content);
			$document->setResizedCopy($resized);
		}
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
			'image/png',
			'image/webp',
			// A browser cannot draw these two, but a phone produces them: HEIC
			// is the iPhone camera default. They are accepted here and
			// converted on the way in -- see ImageConversionService. Refusing
			// them meant telling most of an iPhone's owners to convert their
			// own photos before posting.
			'image/avif',
			'image/heic',
			'image/heif',
			'video/mp4',
			'video/webm',
			'video/quicktime',
			'audio/mpeg',
			'audio/mp4',
			'audio/ogg',
			'audio/opus',
			'audio/wav',
			'audio/x-wav',
			'audio/flac',
			'audio/aac',
		];

		if (in_array($mime, $allowedMimeType)) {
			return;
		}

		throw new CacheContentMimeTypeException();
	}

	/**
	 * Builds the preview copy, and refuses to try on content that only claims
	 * to be an image.
	 *
	 * The mime type is sniffed from magic bytes alone, so a PNG header followed
	 * by garbage arrives here looking like a picture. Decoding it fails, and the
	 * failure has to surface as an exception the caller can record against the
	 * document: an unreported failure here used to escape as an `Error` and
	 * abandon caching for every document queued behind this one.
	 *
	 * @throws CacheContentDecodeException
	 */
	private function resizeImage(Document $document, string &$content): void {
		$this->assertDecodable($content);

		try {
			$image = ImageResize::createFromString($content);
		} catch (ImageResizeException $e) {
			throw new CacheContentDecodeException('cannot read image: ' . $e->getMessage());
		} catch (Throwable $e) {
			// the library reaches into GD, which reports some failures as a
			// warning plus a false return rather than as an exception
			throw new CacheContentDecodeException('cannot read image: ' . $e->getMessage());
		}

		try {
			$image->quality_jpg = 80;
			$image->quality_png = 7;
			$image->quality_webp = 80;

			$image->resizeToBestFit(self::RESIZED_WIDTH, self::RESIZED_HEIGHT);
			$newContent = $image->getImageAsString();

			if ($newContent) {
				$content = $newContent;
			}
		} catch (ImageResizeException $e) {
			// the original is still usable as the full copy; only the preview
			// is lost, so keep the sizes we do know and carry on
		}

		$document->setLocalCopySize($image->getSourceWidth(), $image->getSourceHeight());
		$document->setResizedCopySize($image->getDestWidth(), $image->getDestHeight());

		$gd = @imagecreatefromstring($content);
		if ($gd !== false) {
			$document->setBlurHash($this->blurService->generateBlurHash($gd));
		}
	}

	/**
	 * Reads the dimensions out of the image header and refuses anything whose
	 * decode would not fit in memory, before a single pixel is decoded.
	 *
	 * @throws CacheContentDecodeException
	 */
	private function assertDecodable(string $content): void {
		$size = @getimagesizefromstring($content);
		if ($size === false) {
			throw new CacheContentDecodeException('content is not a readable image');
		}

		$width = $size[0] ?? 0;
		$height = $size[1] ?? 0;
		if ($width < 1 || $height < 1) {
			throw new CacheContentDecodeException('image has no usable dimensions');
		}

		if ($width * $height > self::MAX_PIXELS) {
			throw new CacheContentDecodeException(
				'image is too large to decode: ' . $width . 'x' . $height
			);
		}
	}

	/**
	 * @param string $filename
	 *
	 * @return ISimpleFile
	 * @throws CacheContentException
	 * @throws CacheDocumentDoesNotExistException
	 */
	/**
	 * Removes a cached copy from appdata; a missing or empty filename (or the
	 * 'avatar' placeholder) is a no-op — retention must never abort on a file
	 * that is already gone.
	 */
	public function removeFromCache(string $filename): void {
		if ($filename === '' || $filename === 'avatar') {
			return;
		}

		try {
			$this->appData->getFolder($this->generatePath($filename))
				->getFile($filename)
				->delete();
		} catch (Exception $e) {
		}
	}

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
	/**
	 * Opens a streamed document's file at its origin, without storing any of it.
	 *
	 * The counterpart of `retrieveContent()` for the one kind of document that
	 * is deliberately never copied here -- see `Document::COPY_STREAMED`. The
	 * client's `Range` is forwarded so that seeking in a long video asks the
	 * origin for the part that was seeked to, and the origin's answer comes
	 * back untouched for the caller to pass on.
	 *
	 * @param string $range the client's own `Range` header, or ''
	 *
	 * @return array{stream: resource, status: int, headers: array<string, string[]>}
	 *
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function openRemoteFile(Document $document, string $range = ''): array {
		$url = $document->getUrl();
		$parsed = parse_url($url);
		if (!is_array($parsed)) {
			throw new RequestServerException('unreadable url');
		}

		if (!in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'], true)) {
			throw new RequestServerException('unsupported scheme');
		}

		$headers = [];
		// only the shape a media element actually sends, and only that shape:
		// the header is written by whoever is reading and reaches another
		// server as it was written
		if ($range !== '' && preg_match('/^bytes=\d*-\d*(, ?\d*-\d*)*$/', $range) === 1) {
			$headers['Range'] = $range;
		}

		return $this->curlService->openStream($url, $headers);
	}

	public function retrieveContent(string $url): string {
		$parsed = parse_url($url);
		if (!is_array($parsed)) {
			throw new RequestServerException('unreadable url');
		}

		$scheme = strtolower($parsed['scheme'] ?? '');
		if (!in_array($scheme, ['http', 'https'], true)) {
			// every other scheme curl or the client could be talked into
			// (file://, gopher://, …) reads something that is not the web
			throw new RequestServerException('unsupported scheme: ' . $scheme);
		}

		$this->mustContains(['path', 'host', 'scheme'], $parsed);

		// the url is fetched as it is written: a signed CDN link carries its
		// credentials in the query string, and re-encoding one turned every
		// such attachment into a 403
		return $this->curlService->doRequest('get', $url, ['json_headers' => false]);
	}
}
