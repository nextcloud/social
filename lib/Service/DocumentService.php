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


namespace OCA\Social\Service;


use daita\MySmallPhpTools\Exceptions\MalformedArrayException;
use daita\MySmallPhpTools\Exceptions\RequestContentException;
use daita\MySmallPhpTools\Exceptions\RequestNetworkException;
use daita\MySmallPhpTools\Exceptions\RequestResultNotJsonException;
use daita\MySmallPhpTools\Exceptions\RequestResultSizeException;
use daita\MySmallPhpTools\Exceptions\RequestServerException;
use Exception;
use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheContentException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IURLGenerator;


class DocumentService {


	const ERROR_SIZE = 1;
	const ERROR_MIMETYPE = 2;
	const ERROR_PERMISSION = 3;


	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var CacheDocumentsRequest */
	private $cacheDocumentsRequest;

	/** @var ActorsRequest */
	private $actorRequest;

	/** @var StreamRequest */
	private $streamRequest;

	/** @var CacheDocumentService */
	private $cacheService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * DocumentInterface constructor.
	 *
	 * @param IUrlGenerator $urlGenerator
	 * @param CacheDocumentsRequest $cacheDocumentsRequest
	 * @param ActorsRequest $actorRequest
	 * @param StreamRequest $streamRequest
	 * @param CacheDocumentService $cacheService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IUrlGenerator $urlGenerator, CacheDocumentsRequest $cacheDocumentsRequest,
		ActorsRequest $actorRequest, StreamRequest $streamRequest,
		CacheDocumentService $cacheService, ConfigService $configService, MiscService $miscService
	) {
		$this->urlGenerator = $urlGenerator;
		$this->cacheDocumentsRequest = $cacheDocumentsRequest;
		$this->actorRequest = $actorRequest;
		$this->streamRequest = $streamRequest;
		$this->configService = $configService;
		$this->cacheService = $cacheService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $id
	 * @param bool $public
	 *
	 * @return Document
	 * @throws CacheDocumentDoesNotExistException
	 * @throws MalformedArrayException
	 * @throws SocialAppConfigException
	 */
	public function cacheRemoteDocument(string $id, bool $public = false) {
		$document = $this->cacheDocumentsRequest->getById($id, $public);
		if ($document->getError() > 0) {
			throw new CacheDocumentDoesNotExistException();
		}

		if ($document->getLocalCopy() !== '') {
			return $document;
		}

		if ($document->getCaching() > (time() - (CacheDocumentsRequest::CACHING_TIMEOUT * 60))) {
			return $document;
		}

		$mime = '';
		$this->cacheDocumentsRequest->initCaching($document);

		try {
			$this->cacheService->saveRemoteFileToCache($document, $mime);
			$this->cacheDocumentsRequest->endCaching($document);

			$this->streamRequest->updateAttachments($document);

			return $document;
		} catch (CacheContentMimeTypeException $e) {
			$this->miscService->log(
				'Not allowed mime type ' . json_encode($document) . ' ' . json_encode($e), 1
			);
			$document->setMimeType($mime);
			$document->setError(self::ERROR_MIMETYPE);
			$this->cacheDocumentsRequest->endCaching($document);
		} catch (NotFoundException $e) {
			$this->miscService->log(
				'Cannot save cache file ' . json_encode($document) . ' ' . json_encode($e), 1
			);
			$document->setError(self::ERROR_PERMISSION);
			$this->cacheDocumentsRequest->endCaching($document);
		} catch (NotPermittedException $e) {
			$this->miscService->log(
				'Cannot save cache file ' . json_encode($document) . ' ' . json_encode($e), 1
			);
			$document->setError(self::ERROR_PERMISSION);
			$this->cacheDocumentsRequest->endCaching($document);
		} catch (RequestResultSizeException $e) {
			$this->miscService->log(
				'Downloaded file is too big ' . json_encode($document) . ' ' . json_encode($e), 1
			);
			$document->setError(self::ERROR_SIZE);
			$this->cacheDocumentsRequest->endCaching($document);
		} catch (RequestContentException $e) {
			$this->cacheDocumentsRequest->deleteById($id);
		} catch (UnauthorizedFediverseException $e) {
			$this->cacheDocumentsRequest->deleteById($id);
		} catch (RequestNetworkException $e) {
			$this->cacheDocumentsRequest->endCaching($document);
		} catch (RequestServerException $e) {
			$this->cacheDocumentsRequest->endCaching($document);
		}

		throw new CacheDocumentDoesNotExistException();
	}


	/**
	 * @param string $id
	 * @param string $mime
	 * @param bool $public
	 *
	 * @return ISimpleFile
	 * @throws CacheContentException
	 * @throws CacheDocumentDoesNotExistException
	 * @throws MalformedArrayException
	 * @throws RequestResultNotJsonException
	 * @throws SocialAppConfigException
	 */
	public function getResizedFromCache(string $id, string &$mime = '', bool $public = false) {
		$document = $this->cacheRemoteDocument($id, $public);
		$mime = $document->getMimeType();

		return $this->cacheService->getContentFromCache($document->getResizedCopy());
	}


	/**
	 * @param string $id
	 * @param bool $public
	 * @param string $mimeType
	 *
	 * @return ISimpleFile
	 * @throws CacheContentException
	 * @throws CacheDocumentDoesNotExistException
	 * @throws MalformedArrayException
	 * @throws SocialAppConfigException
	 */
	public function getFromCache(string $id, string &$mimeType = '', bool $public = false): ISimpleFile {
		$document = $this->cacheRemoteDocument($id, $public);
		$mimeType = $document->getMimeType();

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
			if ($item->getLocalCopy() === 'avatar') {
				continue;
			}

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
	 * @param Person $actor
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemUnknownException
	 * @throws ItemAlreadyExistsException
	 */
	public function cacheLocalAvatarByUsername(Person $actor): string {
		$url = $this->urlGenerator->linkToRouteAbsolute(
			'core.avatar.getAvatar', ['userId' => $actor->getUserId(), 'size' => 128]
		);

		$versionCurrent =
			(int)$this->configService->getUserValue('version', $actor->getUserId(), 'avatar');
		$versionCached = $actor->getAvatarVersion();
		if ($versionCurrent > $versionCached) {
			/** @var Image $icon */
			$icon = AP::$activityPub->getItemFromType(Image::TYPE);
			$icon->generateUniqueId('/documents/avatar');
			$icon->setUrl($url);
			$icon->setMediaType('');
			$icon->setLocalCopy('avatar');

			$interface = AP::$activityPub->getInterfaceFromType(Image::TYPE);
			$interface->save($icon);

			$actor->setAvatarVersion($versionCurrent);
			$this->actorRequest->update($actor);
		} else {
			try {
				$icon = $this->cacheDocumentsRequest->getByUrl($url);
			} catch (CacheDocumentDoesNotExistException $e) {
				return '';
			}
		}

		return $icon->getId();
	}

}

