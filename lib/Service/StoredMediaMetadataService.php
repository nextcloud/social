<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoVideoRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Takes the container metadata off a video or sound file stored before
 * uploads lost it on the way in (see `CacheDocumentService::saveMediaFromTemp()`).
 *
 * The rewritten file is stored under a new uuid and the old one deleted,
 * as the transcoder does: a stored file is served as immutable by its uuid,
 * so bytes changed under the same name would leave the old ones in every
 * cache that holds them. The posts carrying it are pointed at the new copy.
 *
 * A file that is a blob in a Bluesky repository, or was handed to Bluesky's
 * video service, is left alone: its CID is the hash of the bytes, and
 * rewriting them would break the record that names it.
 */
class StoredMediaMetadataService {
	public const STRIPPED = 'stripped';
	public const ALREADY_CLEAN = 'clean';
	public const ON_BLUESKY = 'bluesky';
	public const MISSING = 'missing';
	public const FAILED = 'failed';

	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private CacheDocumentService $cacheDocumentService,
		private VideoTranscodeService $videoTranscodeService,
		private AtprotoBlobRequest $atprotoBlobRequest,
		private AtprotoVideoRequest $atprotoVideoRequest,
		private StreamRequest $streamRequest,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
	) {
	}

	/** Whether the file has to stay byte for byte as it is, for Bluesky. */
	public function isOnBluesky(Document $document): bool {
		return $this->atprotoBlobRequest->hasDocument($document->getId())
			|| $this->atprotoVideoRequest->hasDocument($document->getId());
	}

	/**
	 * Rewrites one stored file without its container metadata, and says what
	 * became of it (one of the constants above). A file ffmpeg cannot rewrite
	 * is left as it is.
	 */
	public function strip(Document $document): string {
		if ($this->isOnBluesky($document)) {
			return self::ON_BLUESKY;
		}

		$source = $this->toTempFile($document);
		if ($source === null) {
			return self::MISSING;
		}

		$stripped = null;
		try {
			$stripped = $this->videoTranscodeService->withoutMetadata($source, $document->getMediaType());
			if ($stripped === null) {
				$this->logger->info('the metadata of a stored file could not be taken off; it was left as it is', [
					'document' => $document->getId(), 'type' => $document->getMediaType(),
				]);

				return self::FAILED;
			}

			if (hash_file('sha256', $stripped) === hash_file('sha256', $source)) {
				return self::ALREADY_CLEAN;
			}

			$this->replace($document, $stripped);

			return self::STRIPPED;
		} catch (Throwable $e) {
			$this->logger->warning('the metadata of a stored file could not be taken off', [
				'document' => $document->getId(), 'exception' => $e,
			]);

			return self::FAILED;
		} finally {
			@unlink($source);
			if ($stripped !== null) {
				@unlink($stripped);
			}
		}
	}

	/**
	 * Written first, pointed at second, the original deleted last: a failure
	 * anywhere leaves the document pointing at a file that exists.
	 */
	private function replace(Document $document, string $stripped): void {
		$stored = $this->cacheDocumentService->storeFile($stripped);
		$size = (int)filesize($stripped);
		$was = $document->getLocalCopy();

		$this->cacheDocumentsRequest->replaceLocalCopy($document->getNid(), $stored, $size);

		$rewritten = clone $document;
		$rewritten->setLocalCopy($stored);
		$rewritten->setSizeBytes($size);
		$rewritten->setLaddered(0);
		try {
			$this->streamRequest->updateLocalAttachmentCopies($rewritten);
		} catch (Throwable $e) {
			$this->logger->warning('the posts carrying a rewritten file could not be updated', [
				'document' => $document->getId(), 'exception' => $e,
			]);
		}

		if ($was !== '' && $was !== $stored) {
			try {
				$this->cacheDocumentService->removeFromCache($was);
			} catch (Throwable $e) {
				$this->logger->warning('the original of a rewritten file could not be deleted', [
					'document' => $document->getId(), 'exception' => $e,
				]);
			}
		}
	}

	/** The stored bytes in a temporary file, or null when they are not there. */
	private function toTempFile(Document $document): ?string {
		try {
			$file = $this->cacheDocumentService->getContentFromCache($document->getLocalCopy());
			$source = $file->read();
		} catch (Throwable $e) {
			return null;
		}

		$path = $this->tempManager->getTemporaryFile();
		if (!is_resource($source) || $path === false) {
			if (is_resource($source)) {
				fclose($source);
			}

			return null;
		}

		$target = fopen($path, 'wb');
		if ($target === false) {
			fclose($source);
			@unlink($path);

			return null;
		}

		try {
			stream_copy_to_stream($source, $target);
		} finally {
			fclose($source);
			fclose($target);
		}

		return $path;
	}
}
