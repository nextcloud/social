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
		CacheDocumentsRequest $cacheDocumentsRequest
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
