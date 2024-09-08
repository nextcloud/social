<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use DateTime;
use Exception;
use JsonSerializable;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Model\Client\AttachmentMetaDim;
use OCA\Social\Model\Client\AttachmentMetaFocus;
use OCA\Social\Model\Client\MediaAttachment;
use OCP\IURLGenerator;

/**
 * Class Document
 *
 * @package OCA\Social\Model\ActivityPub
 */
class Document extends ACore implements JsonSerializable {
	public const TYPE = 'Document';

	private string $account = '';
	private string $mediaType = '';
	private string $mimeType = '';
	private string $localCopy = '';
	private string $resizedCopy = '';
	private string $blurHash = '';
	private ?AttachmentMeta $meta = null;
	private string $description = '';
	private int $caching = 0;
	private bool $public = false;
	private int $error = 0;
	private string $parentId = '';
	private array $localCopySize = [0, 0];
	private array $resizedCopySize = [0, 0];


	/**
	 * Document constructor.
	 *
	 * @param ACore $parent
	 */
	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}


	public function setAccount(string $account): self {
		$this->account = $account;

		return $this;
	}

	public function getAccount(): string {
		return $this->account;
	}


	/**
	 * @return string
	 */
	public function getMediaType(): string {
		return $this->mediaType;
	}

	/**
	 * @param string $mediaType
	 *
	 * @return ACore
	 */
	public function setMediaType(string $mediaType): ACore {
		$this->mediaType = $mediaType;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getMimeType(): string {
		return $this->mimeType;
	}

	/**
	 * @param string $mimeType
	 *
	 * @return ACore
	 */
	public function setMimeType(string $mimeType): ACore {
		$this->mimeType = $mimeType;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getLocalCopy(): string {
		return $this->localCopy;
	}

	/**
	 * @param string $localCopy
	 *
	 * @return Document
	 */
	public function setLocalCopy(string $localCopy): self {
		$this->localCopy = $localCopy;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getResizedCopy(): string {
		return $this->resizedCopy;
	}

	/**
	 * @param string $resizedCopy
	 *
	 * @return Document
	 */
	public function setResizedCopy(string $resizedCopy): self {
		$this->resizedCopy = $resizedCopy;

		return $this;
	}

	public function setLocalCopySize(int $width, int $height): self {
		$this->localCopySize = [$width, $height];

		return $this;
	}

	public function getLocalCopySize(): array {
		return $this->localCopySize;
	}

	public function setResizedCopySize(int $width, int $height): void {
		$this->resizedCopySize = [$width, $height];
	}

	public function getResizedCopySize(): array {
		return $this->resizedCopySize;
	}


	public function setBlurHash(string $blurHash): self {
		$this->blurHash = $blurHash;

		return $this;
	}

	public function getBlurHash(): string {
		return $this->blurHash;
	}

	public function setMeta(AttachmentMeta $meta): self {
		$this->meta = $meta;

		return $this;
	}

	public function getMeta(): ?AttachmentMeta {
		return $this->meta;
	}

	public function setDescription(string $description): self {
		$this->description = $description;

		return $this;
	}

	public function getDescription(): string {
		return $this->description;
	}


	/**
	 * @return bool
	 */
	public function isPublic(): bool {
		return $this->public;
	}

	/**
	 * @param bool $public
	 *
	 * @return Document
	 */
	public function setPublic(bool $public): self {
		$this->public = $public;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getParentId(): string {
		return $this->parentId;
	}

	/**
	 * @param string $parentId
	 *
	 * @return Document
	 */
	public function setParentId(string $parentId): self {
		$this->parentId = $parentId;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getError(): int {
		return $this->error;
	}

	/**
	 * @param int $error
	 *
	 * @return Document
	 */
	public function setError(int $error): self {
		$this->error = $error;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getCaching(): int {
		return $this->caching;
	}

	/**
	 * @param int $caching
	 *
	 * @return Document
	 */
	public function setCaching(int $caching): self {
		$this->caching = $caching;

		return $this;
	}


	/**
	 * @param array $data
	 *
	 * @throws UrlCloudException
	 * @throws InvalidOriginException
	 */
	public function import(array $data) {
		parent::import($data);

		$this->setMediaType($this->validate(ACore::AS_STRING, 'mediaType', $data, ''));

		if ($this->getId() === '') {
			$this->generateUniqueId('/documents/g');
		} else {
			// TODO: question if we need this, and why during the import ?
			//			$this->checkOrigin($this->getId());
		}
	}


	/**
	 * @param array $data
	 */
	public function importFromDatabase(array $data) {
		parent::importFromDatabase($data);

		$this->setAccount($this->get('account', $data));
		$this->setPublic(($this->getInt('public', $data, 0) === 1));
		$this->setError($this->getInt('error', $data, 0));
		$this->setLocalCopy($this->get('local_copy', $data, ''));
		$this->setResizedCopy($this->get('resized_copy', $data, ''));
		$this->setBlurHash($this->get('blurhash', $data, ''));
		$this->setDescription($this->get('description', $data, ''));
		$this->setMediaType($this->get('media_type', $data, ''));
		$this->setMimeType($this->get('mime_type', $data, ''));
		$this->setParentId($this->get('parent_id', $data, ''));

		if ($this->get('caching', $data, '') === '') {
			$this->setCaching(0);
		} else {
			try {
				$date = new DateTime($this->get('caching', $data, ''));
				$this->setCaching($date->getTimestamp());
			} catch (Exception $e) {
			}
		}

		if ($this->get('meta', $data) !== '') {
			$meta = new AttachmentMeta();
			$meta->import($this->getArray('meta', $data));
			$this->setMeta($meta);
		}
	}

	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		$result = array_merge(
			parent::jsonSerialize(),
			[
				'mediaType' => $this->getMediaType(),
				'mimeType' => $this->getMimeType(),
				'localCopy' => $this->getLocalCopy(),
				'resizedCopy' => $this->getResizedCopy()
			]
		);

		if ($this->isCompleteDetails()) {
			$result['parentId'] = $this->getParentId();
		}

		return $result;
	}


	public function getMediaUrl(IURLGenerator $urlGenerator, string $mime = ''): string {
		$mime = ($mime === '') ? '' : '.' . $mime;

		return $urlGenerator->linkToRouteAbsolute(
			'social.Api.mediaOpen',
			['uuid' => $this->getLocalCopy() . $mime]
		);
	}

	public function getResizedMediaUrl(IURLGenerator $urlGenerator, string $mime = ''): string {
		$mime = ($mime === '') ? '' : '.' . $mime;

		return $urlGenerator->linkToRouteAbsolute(
			'social.Api.mediaOpen',
			['uuid' => $this->getResizedCopy() . $mime]
		);
	}


	/**
	 * @param IURLGenerator|null $urlGenerator
	 *
	 * @return MediaAttachment
	 */
	public function convertToMediaAttachment(
		?IURLGenerator $urlGenerator = null,
		int $exportFormat = self::FORMAT_LOCAL
	): MediaAttachment {
		$media = new MediaAttachment();
		$media->setId((string)$this->getNid())
			  ->setExportFormat($exportFormat);

		$mime = '';
		if (strpos($this->getMediaType(), '/')) {
			[$type, $mime] = explode('/', $this->getMediaType(), 2);
			$media->setType($type);
		}

		if (!is_null($urlGenerator)) {
			$media->setUrl($this->getMediaUrl($urlGenerator, $mime));
			$media->setPreviewUrl($this->getResizedMediaUrl($urlGenerator, $mime));
		}

		$media->setRemoteUrl($this->getUrl());

		if ($this->getMeta() === null) {
			$meta = new AttachmentMeta();
			$meta->setOriginal(new AttachmentMetaDim($this->getLocalCopySize()))
				 ->setSmall(new AttachmentMetaDim($this->getResizedCopySize()))
				 ->setFocus(new AttachmentMetaFocus(0, 0));

			$this->setMeta($meta);
		}

		$media->setMeta($this->getMeta())
			  ->setDescription($this->getDescription())
			  ->setBlurHash($this->getBlurHash());

		return $media;
	}
}
