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
	protected CacheDocumentService $cacheDocumentService;
	protected CacheDocumentsRequest $cacheDocumentsRequest;

	public function __construct(
		CacheDocumentService $cacheDocumentService,
		CacheDocumentsRequest $cacheDocumentsRequest,
	) {
		$this->cacheDocumentService = $cacheDocumentService;
		$this->cacheDocumentsRequest = $cacheDocumentsRequest;
	}

	/**
	 * @throws InvalidOriginException
	 */
	public function activity(Acore $activity, ACore $item): void {
		if ($activity->getType() === Person::TYPE) {
			$activity->checkOrigin($item->getId());
		}
	}

	public function save(ACore $item): void {
		/** @var Document $item */
		if (!$item->isRoot()) {
			$item->setParentId(
				$item->getParent()
					->getId()
			);
		}

		try {
			$this->cacheDocumentsRequest->getById($item->getId());
			$this->cacheDocumentsRequest->update($item);
		} catch (CacheDocumentDoesNotExistException $e) {
			if (!$item->isLocal()) {
				$this->cacheDocumentService->saveRemoteFileToCache($item);    // create local copy
			}

			// parentId / url can only be empty on new document, meaning owner cannot be empty here
			if (($item->getUrl() === '' && $item->getParentId() === '' && $item->getAccount() !== '')
				|| !$this->cacheDocumentsRequest->isDuplicate($item)) {
				$this->cacheDocumentsRequest->save($item);
			}
		}
	}
}
