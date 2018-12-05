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
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheContentException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheContentSizeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Document;
use OCA\Social\Model\ActivityPub\Image;
use OCA\Social\Model\ActivityPub\Person;
use OCA\Social\Service\CacheService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IURLGenerator;


class DocumentService implements ICoreService {


	const ERROR_SIZE = 1;
	const ERROR_MIMETYPE = 2;


	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var CacheDocumentsRequest */
	private $cacheDocumentsRequest;

	/** @var ActorsRequest */
	private $actorRequest;

	/** @var CacheService */
	private $cacheService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * DocumentService constructor.
	 *
	 * @param IUrlGenerator $urlGenerator
	 * @param CacheDocumentsRequest $cacheDocumentsRequest
	 * @param ActorsRequest $actorRequest
	 * @param CacheService $cacheService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IUrlGenerator $urlGenerator, CacheDocumentsRequest $cacheDocumentsRequest,
		ActorsRequest $actorRequest,
		CacheService $cacheService,
		ConfigService $configService, MiscService $miscService
	) {
		$this->urlGenerator = $urlGenerator;
		$this->cacheDocumentsRequest = $cacheDocumentsRequest;
		$this->actorRequest = $actorRequest;
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

		if ($document->getCaching() > (time() - (CacheDocumentsRequest::CACHING_TIMEOUT * 60))) {
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
	 */
	public function cacheLocalAvatarByUsername(Person $actor): string {
		$url = $this->urlGenerator->linkToRouteAbsolute(
			'core.avatar.getAvatar', ['userId' => $actor->getUserId(), 'size' => 128]
		);

		$versionCurrent =
			(int)$this->configService->getUserValue('version', $actor->getUserId(), 'avatar');
		$versionCached = $actor->getAvatarVersion();

		if ($versionCurrent > $versionCached) {
			echo $actor->getUserId() . '  ' . $versionCurrent . '   ' . $versionCached . "\n";

			$icon = new Image();
			$icon->setUrl($url);
			$icon->setUrlcloud($this->configService->getCloudAddress());
			$icon->generateUniqueId('/documents/avatar');
			$icon->setMediaType('');
			$icon->setLocalCopy('avatar');

			$this->cacheDocumentsRequest->deleteByUrl($icon->getUrl());
			$this->cacheDocumentsRequest->save($icon);

			$actor->setAvatarVersion($versionCurrent);
			$this->actorRequest->update($actor);
		} else {
			try {
				$icon = $this->cacheDocumentsRequest->getBySource($url);
			} catch (CacheDocumentDoesNotExistException $e) {
				return '';
			}
		}

		return $icon->getId();
	}


	/**
	 * @param ACore $item
	 */
	public function parse(ACore $item) {
	}


	/**
	 * @param ACore $item
	 */
	public function save(ACore $item) {
		/** @var Document $item */
		$this->cacheDocumentsRequest->save($item);
	}


	/**
	 * @param ACore $item
	 */
	public function delete(ACore $item) {
//		$this->cacheDocumentsRequest->delete($item);
	}


}

