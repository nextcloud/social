<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finding the next video to convert, converting it, and putting it back.
 *
 * Separate from `VideoTranscodeService`, which knows only how to run ffmpeg
 * over a path: this one knows about stored documents, and that separation is
 * what lets the ffmpeg command be tested without a file store and the
 * bookkeeping be tested without ffmpeg.
 *
 * The order of operations is the whole of the care here. The converted file is
 * written to the store **first**, the row is pointed at it **second**, and the
 * original is deleted **last** — so a failure at any point leaves a document
 * pointing at a file that exists. The other order leaves a post with a video
 * that 404s, which is worse than a video in a format some servers will not
 * take.
 */
class VideoTranscodingWorker {
	/** The four states `social_cache_doc.transcoded` holds. */
	public const NOT_LOOKED = 0;
	public const CONVERTED = 1;
	public const NOT_NEEDED = 2;
	public const FAILED = 3;

	/** How many candidates are read to find one worth converting. */
	private const PAGE = 20;

	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private CacheDocumentService $cacheDocumentService,
		private VideoTranscodeService $videoTranscodeService,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Converts the next video that wants it.
	 *
	 * Anything already in the target format is marked as not needing it as it
	 * is passed over, which is what stops the same page being read on every
	 * run for ever.
	 *
	 * @return bool whether a video was actually converted
	 */
	public function convertNext(): bool {
		foreach ($this->cacheDocumentsRequest->getVideosToTranscode(self::PAGE) as $document) {
			if (!$this->videoTranscodeService->shouldConvert($document->getMediaType())) {
				$this->cacheDocumentsRequest->setTranscoded($document->getNid(), self::NOT_NEEDED);
				continue;
			}

			return $this->convert($document);
		}

		return false;
	}

	/**
	 * One video: out of the store, through ffmpeg, back into the store.
	 */
	public function convert(Document $document): bool {
		$source = null;

		try {
			$source = $this->toTempFile($document);
			if ($source === null) {
				// the bytes are not there to convert. Not a failure of the
				// conversion — marked as tried so the job moves on rather than
				// reading the same missing file for ever.
				$this->cacheDocumentsRequest->setTranscoded($document->getNid(), self::FAILED);

				return false;
			}

			$converted = $this->videoTranscodeService->convert($source);
			if ($converted === null) {
				$this->logger->info('a video could not be converted and was left as it is', [
					'document' => $document->getId(), 'type' => $document->getMediaType(),
				]);
				$this->cacheDocumentsRequest->setTranscoded($document->getNid(), self::FAILED);

				return false;
			}

			// written first, pointed at second, and the original deleted last:
			// a failure anywhere in here leaves the document pointing at a
			// file that exists
			$stored = $this->cacheDocumentService->storeFile($converted);
			@unlink($converted);

			$was = $document->getLocalCopy();
			$this->cacheDocumentsRequest->replaceVideo(
				$document->getNid(), $stored, VideoTranscodeService::TARGET_TYPE
			);

			if ($was !== '' && $was !== $stored) {
				try {
					$this->cacheDocumentService->removeFromCache($was);
				} catch (Throwable $e) {
					// the row already points at the new file, so this is disk
					// left behind rather than anything a reader can see
					$this->logger->debug('the original of a converted video could not be deleted', [
						'document' => $document->getId(), 'exception' => $e,
					]);
				}
			}

			return true;
		} catch (Throwable $e) {
			$this->logger->warning('a video could not be converted', [
				'document' => $document->getId(), 'exception' => $e,
			]);
			$this->cacheDocumentsRequest->setTranscoded($document->getNid(), self::FAILED);

			return false;
		} finally {
			if ($source !== null) {
				@unlink($source);
			}
		}
	}

	/**
	 * The stored bytes on disk, where ffmpeg can read them.
	 *
	 * @return string|null the path, or null when the file is not there
	 */
	private function toTempFile(Document $document): ?string {
		try {
			$file = $this->cacheDocumentService->getContentFromCache($document->getLocalCopy());
		} catch (Throwable $e) {
			return null;
		}

		$path = $this->tempManager->getTemporaryFile('.video');
		if ($path === false) {
			return null;
		}

		$source = $file->read();
		$target = fopen($path, 'wb');
		if (!is_resource($source) || $target === false) {
			@unlink($path);

			return null;
		}

		// copied a chunk at a time: a video is the one thing this app stores
		// that will not fit in a string
		stream_copy_to_stream($source, $target);
		fclose($target);
		fclose($source);

		return (filesize($path) > 0) ? $path : null;
	}
}
