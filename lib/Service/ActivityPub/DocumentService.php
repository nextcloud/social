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


namespace OCA\Social\Service\ActivityPub;


use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheContentException;
use OCA\Social\Exceptions\CacheContentSizeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Document;
use OCA\Social\Service\CacheService;
use OCA\Social\Service\ICoreService;
use OCA\Social\Service\MiscService;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;


class DocumentService implements ICoreService {


	/** @var CacheDocumentsRequest */
	private $cacheDocumentsRequest;

	/** @var CacheService */
	private $cacheService;

	/** @var MiscService */
	private $miscService;


	/**
	 * DocumentService constructor.
	 *
	 * @param CacheDocumentsRequest $cacheDocumentsRequest
	 * @param CacheService $cacheService
	 * @param MiscService $miscService
	 */
	public function __construct(
		CacheDocumentsRequest $cacheDocumentsRequest, CacheService $cacheService,
		MiscService $miscService
	) {
		$this->cacheDocumentsRequest = $cacheDocumentsRequest;
		$this->cacheService = $cacheService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $id
	 *
	 * @return Document
	 * @throws CacheDocumentDoesNotExistException
	 * @throws NotPermittedException
	 */
	public function cacheRemoteDocument(string $id) {
		$document = $this->cacheDocumentsRequest->getById($id);
		if ($document->getLocalCopy() !== '') {
			return $document;
		}

		// TODO - check the size of the attachment, also to stop download after a certain size of content.
		// TODO - ignore this is getCaching is older than 15 minutes
		if ($document->getCaching() !== '') {
			return $document;
		}

		$this->cacheDocumentsRequest->initCaching($document);

		try {
			$localCopy = $this->cacheService->saveRemoteFileToCache($document->getUrl(), $mime);
			$document->setMimeType($mime);
			$document->setLocalCopy($localCopy);
			$this->cacheDocumentsRequest->endCaching($document);
		} catch (CacheContentSizeException $e) {
			$this->cacheDocumentsRequest->endCaching($document);
		} catch (CacheContentException $e) {
		}

		return $document;
	}


	/**
	 * @param string $id
	 *
	 * @param bool $public
	 *
	 * @return ISimpleFile
	 * @throws CacheContentException
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getFromCache(string $id, bool $public = false) {
		$document = $this->cacheDocumentsRequest->getById($id, $public);

		return $this->cacheService->getContentFromCache($document->getLocalCopy());
	}


	/**
	 * @param ACore $item
	 */
	public function parse(ACore $item) {
		// TODO: Implement parse() method.
	}

	/**
	 * @param ACore $item
	 */
	public function delete(ACore $item) {
		// TODO: Implement delete() method.
	}

}

