<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheContentDecodeException;
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
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IURLGenerator;
use Throwable;

class DocumentService {
	public const ERROR_SIZE = 1;
	public const ERROR_MIMETYPE = 2;
	public const ERROR_PERMISSION = 3;

	/**
	 * The bytes could not be decoded as the image they claimed to be, or were
	 * too large to decode. Recorded on the row so the caching run stops
	 * offering it again on every pass.
	 */
	public const ERROR_CONTENT = 4;

	public function __construct(
		private IUrlGenerator $urlGenerator,
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private ActorsRequest $actorRequest,
		private StreamRequest $streamRequest,
		private CacheDocumentService $cacheService,
		private ConfigService $configService,
		private MiscService $miscService,
	) {
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
			// the mime type is sniffed from the bytes at this point and nowhere
			// else; unpersisted, the copy is later served with no Content-Type
			if ($mime !== '') {
				$document->setMimeType($mime);
				if ($document->getMediaType() === '') {
					$document->setMediaType($mime);
				}
			}
			$this->cacheDocumentsRequest->endCaching($document);

			$this->streamRequest->updateAttachments($document);

			return $document;
		} catch (CacheContentDecodeException $e) {
			// A remote peer can hand us a PNG header followed by garbage, which
			// passes the mime sniff and then fails to decode. Left unrecorded the
			// row comes back on every caching run, so mark it and move on.
			$this->miscService->log(
				'Cannot decode document ' . json_encode($document) . ' ' . json_encode($e), 1
			);
			$document->setError(self::ERROR_CONTENT);
			$this->cacheDocumentsRequest->endCaching($document);
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
			$this->cacheDocumentsRequest->deleteById($document->getId());
		} catch (UnauthorizedFediverseException $e) {
			$this->cacheDocumentsRequest->deleteById($document->getId());
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
	public function getFromCache(
		string $id, string &$mimeType = '', bool $public = false,
	): ISimpleFile {
		$document = $this->cacheRemoteDocument($id, $public);
		$mimeType = $document->getMimeType();

		return $this->cacheService->getContentFromCache($document->getLocalCopy());
	}

	/**
	 * The document behind an id, as the viewer is allowed to see it.
	 *
	 * `/document/get` and `/document/get/resized` take a bare document id from
	 * whoever is asking, so a session alone must not be enough: without this
	 * check any account could name any id and read another account's
	 * attachments, direct messages included. Same rule as `/media/{uuid}`,
	 * widened by what the viewer's own timeline already shows them.
	 *
	 * @throws CacheContentException
	 * @throws CacheDocumentDoesNotExistException
	 * @throws MalformedArrayException
	 * @throws SocialAppConfigException
	 */
	public function getFromCacheAsViewer(
		string $id, ?Person $viewer, string &$mimeType = '',
	): ISimpleFile {
		$this->assertViewerMayRead($id, $viewer);

		return $this->getFromCache($id, $mimeType);
	}

	/**
	 * The preview copy behind an id, as the viewer is allowed to see it.
	 *
	 * @throws CacheContentException
	 * @throws CacheDocumentDoesNotExistException
	 * @throws MalformedArrayException
	 * @throws SocialAppConfigException
	 */
	public function getResizedFromCacheAsViewer(
		string $id, ?Person $viewer, string &$mimeType = '',
	): ISimpleFile {
		$this->assertViewerMayRead($id, $viewer);

		return $this->getResizedFromCache($id, $mimeType);
	}

	/**
	 * Caches (if needed) and returns a document the viewer is allowed to see.
	 *
	 * @throws CacheDocumentDoesNotExistException
	 * @throws MalformedArrayException
	 * @throws SocialAppConfigException
	 */
	public function cacheRemoteDocumentAsViewer(string $id, ?Person $viewer): Document {
		$this->assertViewerMayRead($id, $viewer);

		return $this->cacheRemoteDocument($id);
	}

	/**
	 * @throws CacheDocumentDoesNotExistException
	 */
	private function assertViewerMayRead(string $id, ?Person $viewer): void {
		$document = $this->cacheDocumentsRequest->getById($id);

		if (!$this->viewerMayRead($document, $viewer)) {
			// deliberately indistinguishable from an id that does not exist:
			// probing must not tell the caller which ids are real
			throw new CacheDocumentDoesNotExistException('unknown document');
		}
	}

	/**
	 * A public copy is readable by anyone, as `/media/{uuid}` already decided.
	 * Beyond that a viewer may read their own uploads, their own actor's avatar
	 * and header, and whatever hangs off a post their timeline would show them —
	 * which is the check that keeps a direct message's attachment private.
	 */
	private function viewerMayRead(Document $document, ?Person $viewer): bool {
		if ($document->isPublic()) {
			return true;
		}

		if ($viewer === null) {
			return false;
		}

		$account = $document->getAccount();
		if ($account !== '' && $account === $viewer->getPreferredUsername()) {
			return true;
		}

		$parentId = $document->getParentId();
		if ($parentId === '') {
			return false;
		}

		if ($parentId === $viewer->getId()) {
			return true;
		}

		try {
			$this->streamRequest->setViewer($viewer);

			// asViewer applies the same visibility the timelines do
			$this->streamRequest->getStreamById($parentId, true);

			return true;
		} catch (Exception $e) {
			return false;
		}
	}

	/**
	 * The cached copy of a remote url, when the caching run has already fetched
	 * it. Lets a route hand over bytes this instance holds rather than pointing
	 * the browser at the remote host.
	 *
	 * @throws CacheContentException
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getCachedFromUrl(string $url, string &$mimeType = ''): ISimpleFile {
		$document = $this->cacheDocumentsRequest->getByUrl($url);
		if ($document->getError() > 0 || $document->getLocalCopy() === '') {
			throw new CacheDocumentDoesNotExistException('not cached');
		}

		$mimeType = $document->getMimeType();

		return $this->cacheService->getContentFromCache($document->getLocalCopy());
	}

	/**
	 * The copy behind `/media/{uuid}`, with the row that describes it.
	 *
	 * The uuid *is* the access control. Every copy is named by a version-4
	 * uuid drawn from `random_bytes()` (`TStringTools::uuid()`), 122 bits
	 * nobody can enumerate, and the link is only ever handed to the people a
	 * post was addressed to. This is Mastodon's own model — media is a
	 * capability URL, reachable by whoever holds it, whatever the visibility
	 * of the post it hangs off — and it is the model the rest of the fediverse
	 * relies on: a remote server fetches an attachment unsigned, on behalf of
	 * a reader it has already checked. This method used to refuse any row not
	 * flagged `public`, which turned every followers-only or direct post with
	 * a picture into a broken image on every Mastodon that received it, while
	 * protecting nothing the uuid did not already protect.
	 *
	 * The row is still required — no row, no file — so a copy this app has
	 * forgotten about is not served, and so the caller learns the visibility
	 * (`Document::isPublic()`) to decide how shared caches may treat the bytes.
	 *
	 * @return array{0: ISimpleFile, 1: Document}
	 * @throws NotFoundException
	 */
	public function getFromUuid(string $uuid): array {
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)
			!== 1) {
			throw new NotFoundException('invalid document');
		}

		try {
			// either copy: a preview link names the resized one
			$document = $this->cacheDocumentsRequest->getByCopy($uuid);
		} catch (CacheDocumentDoesNotExistException $e) {
			throw new NotFoundException('unknown document');
		}

		return [$this->cacheService->getFromUuid($uuid), $document];
	}

	/**
	 * @param array $getMediaIds
	 * @param string $account
	 *
	 * @return Document[]
	 */
	public function getMediaFromArray(array $getMediaIds, string $account = ''): array {
		return $this->cacheDocumentsRequest->getFromArray($getMediaIds, $account);
	}

	/**
	 * One cached document by its ActivityPub id.
	 *
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getDocumentById(string $id): Document {
		return $this->cacheDocumentsRequest->getById($id);
	}

	/**
	 * Stores a changed alt text.
	 */
	public function updateDescription(Document $document): void {
		$this->cacheDocumentsRequest->updateDescription($document);
	}

	/**
	 * Stores the focal point a client just set.
	 *
	 * The document's cached `meta` is dropped first so that
	 * `convertToMediaAttachment()` rebuilds it from the new focus rather than
	 * handing back the blob it was loaded with.
	 */
	public function updateFocus(Document $document, float $x, float $y): void {
		$document->setFocus($x, $y);
		$document->setMeta(null);
		$document->convertToMediaAttachment($this->urlGenerator);

		$this->cacheDocumentsRequest->updateFocus($document);
	}

	/**
	 * @return int
	 * @throws Exception
	 */
	public function manageCacheDocuments(): int {
		$update = $this->cacheDocumentsRequest->getNotCachedDocuments();

		$count = 0;
		foreach ($update as $item) {
			if ($item->getLocalCopy() === 'avatar' || $item->getLocalCopy() === 'header') {
				continue;
			}

			try {
				$this->cacheRemoteDocument($item->getId());
			} catch (Throwable $e) {
				// One unusable row must never end the run: everything queued
				// behind it would silently stop being cached, on every pass.
				$this->miscService->log(
					'Could not cache document ' . $item->getId() . ' - ' . $e->getMessage(), 1
				);
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

		$versionCurrent
			= (int)$this->configService->getUserValue('version', $actor->getUserId(), 'avatar');
		$versionCached = $actor->getAvatarVersion();
		if ($versionCurrent > $versionCached) {
			/** @var Image $icon */
			$icon = AP::instance()->getItemFromType(Image::TYPE);
			$icon->generateUniqueId('/documents/avatar');
			$icon->setUrl($url);
			$icon->setMediaType('');
			$icon->setLocalCopy('avatar');

			$interface = AP::instance()->getInterfaceFromType(Image::TYPE);
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

	/**
	 * Cache a banner/header image for a local actor.
	 *
	 * @param Person $actor
	 * @param string $tmpPath
	 * @param string $mimeType
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemUnknownException
	 * @throws ItemAlreadyExistsException
	 * @throws CacheContentMimeTypeException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function cacheLocalHeaderByUsername(Person $actor, string $tmpPath, string $mimeType = 'image/jpeg'): string {
		/** @var Image $image */
		$image = AP::instance()->getItemFromType(Image::TYPE);
		$image->generateUniqueId('/documents/header');
		$image->setUrl($this->urlGenerator->linkToRouteAbsolute(
			'social.Local.globalActorHeader', ['id' => $actor->getId()]
		));
		$image->setMediaType($mimeType);
		$image->setMimeType($mimeType);
		$image->setPublic(true);

		$this->cacheService->saveFromTempToCache($image, $tmpPath);

		$image->setUrl($image->getMediaUrl($this->urlGenerator, $image->getMimeType()));

		$interface = AP::instance()->getInterfaceFromType(Image::TYPE);
		$interface->save($image);

		$actor->setHeader($image->getUrl());

		return $image->getId();
	}
}
