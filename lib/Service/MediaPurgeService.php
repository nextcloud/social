<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Model\ActivityPub\Object\Document;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Taking stored media away again.
 *
 * A document is two things: a row in `social_cache_doc`, and one or two files
 * in appdata that only that row knows the names of. Deleting the row first
 * leaves the files behind for good, which is why every caller has to read,
 * remove and only then delete — and why the sequence is here rather than
 * repeated at each caller. It used to be repeated: the remote-actor sweep did
 * it correctly and the `Delete` of a remote account did not, so every
 * federated account deletion left an avatar and a header in appdata.
 */
class MediaPurgeService {
	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private CacheDocumentService $cacheDocumentService,
		private LoggerInterface $logger,
	) {
	}

	/** One document: its files, then its row. */
	public function purge(Document $document): void {
		$this->forgetFiles($document);
		$this->cacheDocumentsRequest->deleteById($document->getId());
	}

	/** One document by its ActivityPub id, when there is a row for it. */
	public function purgeById(string $id): bool {
		if ($id === '') {
			return false;
		}

		try {
			$document = $this->cacheDocumentsRequest->getById($id);
		} catch (CacheDocumentDoesNotExistException $e) {
			return false;
		}

		$this->purge($document);

		return true;
	}

	/**
	 * One of an account's own documents, by the url it is served under.
	 *
	 * The account is checked here rather than by the caller: the only thing a
	 * url identifies is a row, and a banner's url is the one handle its owner
	 * has on it.
	 */
	public function purgeLocalByUrl(string $url, string $account): bool {
		if ($url === '' || $account === '') {
			return false;
		}

		try {
			$document = $this->cacheDocumentsRequest->getByUrl($url);
		} catch (CacheDocumentDoesNotExistException $e) {
			return false;
		}

		if ($document->getAccount() !== $account) {
			return false;
		}

		$this->purge($document);

		return true;
	}

	/**
	 * Everything hanging off one parent -- for an actor, its avatar and header.
	 *
	 * @return int how many rows went
	 */
	public function purgeByParent(string $parentId): int {
		if ($parentId === '') {
			return 0;
		}

		$documents = $this->cacheDocumentsRequest->getByParent($parentId);
		foreach ($documents as $document) {
			$this->forgetFiles($document);
		}

		$this->cacheDocumentsRequest->deleteByParent($parentId);

		return count($documents);
	}

	/**
	 * The documents whose parent no longer exists.
	 *
	 * A row that names a parent names a post, a cached actor or a story, and
	 * when none of the three is there any more nothing can ever refer to the
	 * file again: the attachments of a post that was deleted or never stored,
	 * the avatar of an actor evicted before this sweep existed, the picture of
	 * a story whose day was up.
	 *
	 * Rows with *no* parent are deliberately left alone. For a local upload
	 * that is not evidence of anything: nothing in the schema points from a
	 * post back at the attachment it shows, so "parentless" would sweep away
	 * the pictures of live posts.
	 *
	 * @return int how many rows went
	 */
	public function sweepOrphans(int $days, int $limit): int {
		$swept = 0;
		foreach ($this->cacheDocumentsRequest->getOrphanedByParent($days, $limit) as $document) {
			try {
				$this->purge($document);
				$swept++;
			} catch (Throwable $e) {
				// one row that will not go is not a reason to stop: the rest
				// of the batch is still worth reclaiming
				$this->logger->warning('could not remove an orphaned document', [
					'document' => $document->getId(), 'exception' => $e,
				]);
			}
		}

		if ($swept > 0) {
			$this->logger->info('swept documents whose parent is gone', [
				'days' => $days, 'documents' => $swept,
			]);
		}

		return $swept;
	}

	/**
	 * The files, which only the row knows the names of.
	 *
	 * A file that cannot be removed is not a reason to keep the row: the row
	 * is what makes the bytes reachable, and what is left on disk is what
	 * `occ social:media:usage` reports.
	 */
	private function forgetFiles(Document $document): void {
		try {
			$this->cacheDocumentService->removeFromCache($document->getLocalCopy());
			$this->cacheDocumentService->removeFromCache($document->getResizedCopy());
		} catch (Throwable $e) {
			$this->logger->warning('could not remove the cached copy of ' . $document->getId(), [
				'exception' => $e,
			]);
		}
	}
}
