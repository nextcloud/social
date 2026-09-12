<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Object;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\CacheDocumentService;

class DocumentInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		protected CacheDocumentService $cacheDocumentService,
		protected CacheDocumentsRequest $cacheDocumentsRequest,
	) {
	}

	/**
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function activity(Acore $activity, ACore $item): void {
		if ($activity->getType() === Person::TYPE) {
			$activity->checkOrigin($item->getId());
		}
	}

	#[\Override]
	public function save(ACore $item): void {
		/** @var Document $item */
		if (!$item->isRoot()) {
			$item->setParentId(
				$item->getParent()
					->getId()
			);
		}

		try {
			$known = $this->cacheDocumentsRequest->getById($item->getId());
			$this->keepWhatOnlyTheRowKnows($item, $known);
			$this->cacheDocumentsRequest->update($item);
		} catch (CacheDocumentDoesNotExistException $e) {
			// a streamed document is a pointer at somebody else's file and
			// stays one -- see Document::COPY_STREAMED. Fetching it is the one
			// thing that must not happen here.
			if (!$item->isLocal() && !$item->isStreamed()) {
				$this->cacheDocumentService->saveRemoteFileToCache($item);    // create local copy
			}

			// parentId / url can only be empty on new document, meaning owner cannot be empty here
			if (($item->getUrl() === '' && $item->getParentId() === '' && $item->getAccount() !== '')
				|| !$this->cacheDocumentsRequest->isDuplicate($item)) {
				$this->cacheDocumentsRequest->save($item);
			}
		}
	}

	/**
	 * Moves onto an incoming document the two things that exist only in the
	 * stored row: its key, and where its bytes were put.
	 *
	 * A document arriving off the wire a second time -- a redelivery, an
	 * `Update` of the post it hangs off -- describes a file on somebody else's
	 * server and knows nothing about the copy this instance made of it. Written
	 * as it arrived, it *cleared* `local_copy` and `resized_copy`: the cached
	 * file was orphaned and every post showing that picture broke until the
	 * caching cron happened to fetch it again. The row is the only thing that
	 * knows, so the row is asked.
	 *
	 * The key has the same shape of problem. Without it a re-imported
	 * attachment went back to a client with `id: 0`, and a streamed one would
	 * name row zero to the media proxy -- some other video, or nothing at all.
	 */
	private function keepWhatOnlyTheRowKnows(Document $item, Document $known): void {
		$item->setNid($known->getNid());

		if ($item->getLocalCopy() === '') {
			$item->setLocalCopy($known->getLocalCopy());
		}

		if ($item->getResizedCopy() === '') {
			$item->setResizedCopy($known->getResizedCopy());
		}
	}
}
