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


namespace OCA\Social\Model\ActivityPub\Object;


use DateTime;
use Exception;
use JsonSerializable;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;


/**
 * Class Document
 *
 * @package OCA\Social\Model\ActivityPub
 */
class Document extends ACore implements JsonSerializable {


	const TYPE = 'Document';


	/** @var string */
	private $mediaType = '';

	/** @var string */
	private $mimeType = '';

	/** @var string */
	private $localCopy = '';

	/** @var string */
	private $resizedCopy = '';

	/** @var int */
	private $caching = 0;

	/** @var bool */
	private $public = false;

	/** @var int */
	private $error = 0;

	/** @var string */
	private $parentId = '';

	/**
	 * Document constructor.
	 *
	 * @param ACore $parent
	 */
	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
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
	public function setLocalCopy(string $localCopy): Document {
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
	public function setResizedCopy(string $resizedCopy): Document {
		$this->resizedCopy = $resizedCopy;

		return $this;
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
	public function setPublic(bool $public): Document {
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
	public function setParentId(string $parentId): Document {
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
	public function setError(int $error): Document {
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
	public function setCaching(int $caching): Document {
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

		$this->setPublic(($this->getInt('public', $data, 0) === 1) ? true : false);
		$this->setError($this->getInt('error', $data, 0));
		$this->setLocalCopy($this->get('local_copy', $data, ''));
		$this->setResizedCopy($this->get('resized_copy', $data, ''));
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
	}

	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		$result = array_merge(
			parent::jsonSerialize(),
			[
				'mediaType'   => $this->getMediaType(),
				'mimeType'    => $this->getMimeType(),
				'localCopy'   => $this->getLocalCopy(),
				'resizedCopy' => $this->getResizedCopy()
			]
		);

		if ($this->isCompleteDetails()) {
			$result['parentId'] = $this->getParentId();
		}

		return $result;
	}

}

