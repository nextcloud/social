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


use Exception;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheContentException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheContentSizeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Document;
use OCA\Social\Service\CacheService;
use OCA\Social\Service\MiscService;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;


class DocumentService implements ICoreService {


	const ERROR_SIZE = 1;
	const ERROR_MIMETYPE = 2;


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
	 * @param bool $public
	 *
	 * @return Document
	 * @throws CacheDocumentDoesNotExistException
	 * @throws NotPermittedException
	 */
	public function cacheRemoteDocument(string $id, bool $public = false) {
		$document = $this->cacheDocumentsRequest->getById($id, $public);
		if ($document->getError() > 0) {
			throw new CacheDocumentDoesNotExistException();
		}

		if ($document->getLocalCopy() !== '') {
			return $document;
		}

		if ($document->getCaching() > (time() - (CacheDocumentsRequest::CACHE_TTL * 60))) {
			return $document;
		}

		$mime = '';
		$this->cacheDocumentsRequest->initCaching($document);

		try {
			$localCopy = $this->cacheService->saveRemoteFileToCache($document->getUrl(), $mime);
			$document->setMimeType($mime);
			$document->setLocalCopy($localCopy);
			$this->cacheDocumentsRequest->endCaching($document);

			return $document;
		} catch (CacheContentMimeTypeException $e) {
			$document->setMimeType($mime);
			$document->setError(self::ERROR_MIMETYPE);
			$this->cacheDocumentsRequest->endCaching($document);
		} catch (CacheContentSizeException $e) {
			$document->setError(self::ERROR_SIZE);
			$this->cacheDocumentsRequest->endCaching($document);
		} catch (CacheContentException $e) {
		}

		throw new CacheDocumentDoesNotExistException();
	}


	/**
	 * @param string $id
	 *
	 * @param bool $public
	 *
	 * @return ISimpleFile
	 * @throws CacheContentException
	 * @throws CacheDocumentDoesNotExistException
	 * @throws NotPermittedException
	 */
	public function getFromCache(string $id, bool $public = false) {
		$document = $this->cacheRemoteDocument($id, $public);

		return $this->cacheService->getContentFromCache($document->getLocalCopy());
	}


	/**
	 * @return int
	 * @throws Exception
	 */
	public function manageCacheDocuments(): int {
		$update = $this->cacheDocumentsRequest->getNotCachedDocuments();

		$count = 0;
		foreach ($update as $item) {
			try {
				$this->cacheRemoteDocument($item->getId());
			} catch (Exception $e) {
				continue;
			}
			$count++;
		}

		return $count;
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

