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

namespace OCA\Social\Model\ActivityPub;


use daita\MySmallPhpTools\Traits\TArrayTools;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\InstancePath;


class Item {


	use TArrayTools;


	/** @var string */
	private $urlSocial = '';

	/** @var string */
	private $urlCloud = '';

	/** @var string */
	private $address = '';

	/** @var string */
	private $id = '';

	/** @var string */
	private $type = '';

	/** @var string */
	private $url = '';

	/** @var string */
	private $summary = '';

	/** @var InstancePath[] */
	private $instancePaths = [];

	/** @var string */
	private $to = '';

	/** @var array */
	private $toArray = [];

	/** @var array */
	private $cc = [];

	/** @var array */
	private $bcc = [];

	/** @var string */
	private $published = '';

	/** @var array */
	private $tags = [];

	/** @var Person */
	private $actor = null;

	/** @var string */
	private $actorId = '';

	/** @var string */
	private $iconId = '';

	/** @var string */
	private $objectId = '';

	/** @var bool */
	private $completeDetails = false;

	/** @var string */
	private $source = '';

	/** @var bool */
	private $local = false;

	/** @var string */
	private $origin = '';

	/** @var int */
	private $originSource = 0;

	/** @var int */
	private $originCreationTime = 0;


	/**
	 * @return string
	 */
	public function getId(): string {
		return $this->id;
	}

	/**
	 * @param string $id
	 *
	 * @return Item
	 */
	public function setId(string $id): Item {
		$this->id = $id;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getType(): string {
		return $this->type;
	}

	/**
	 * @param string $type
	 *
	 * @return Item
	 */
	public function setType(string $type): Item {
//		if ($type !== '') {
		$this->type = $type;

//		}

		return $this;
	}


	/**
	 * @return string
	 */
	public function getUrl(): string {
		return $this->url;
	}

	/**
	 * @param string $url
	 *
	 * @return Item
	 */
	public function setUrl(string $url): Item {
		$this->url = $url;

		return $this;
	}


	/**
	 * @param InstancePath $instancePath
	 *
	 * @return Item
	 */
	public function addInstancePath(InstancePath $instancePath): Item {
		if ($instancePath->getUri() !== '') {
			$this->instancePaths[] = $instancePath;
		}

		return $this;
	}


	/**
	 * @param InstancePath[] $path
	 *
	 * @return Item
	 */
	public function addInstancePaths(array $path): Item {
		$this->instancePaths = array_merge($this->instancePaths, $path);

		return $this;
	}

	/**
	 * @return InstancePath[]
	 */
	public function getInstancePaths(): array {
		return $this->instancePaths;
	}

	/**
	 * @param InstancePath[] $instancePaths
	 *
	 * @return Item
	 */
	public function setInstancePaths(array $instancePaths): Item {
		$this->instancePaths = $instancePaths;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getSummary(): string {
		return $this->summary;
	}

	/**
	 * @param string $summary
	 *
	 * @return Item
	 */
	public function setSummary(string $summary): Item {
		$this->summary = $summary;

		return $this;
	}


	/**
	 * @return Person
	 */
	public function getActor(): Person {
		return $this->actor;
	}

	/**
	 * @param Person $actor
	 *
	 * @return Item
	 */
	public function setActor(Person $actor): Item {
		$this->actor = $actor;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function gotActor(): bool {
		if ($this->actor === null) {
			return false;
		}

		return true;
	}


	/**
	 * @param string $actorId
	 *
	 * @return Item
	 */
	public function setActorId(string $actorId): Item {
		$this->actorId = $actorId;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getActorId(): string {
		return $this->actorId;
	}


	/**
	 * @return string
	 */
	public function getUrlSocial(): string {
		return $this->urlSocial;
	}

	/**
	 * @param string $path
	 *
	 * @return Item
	 */
	public function setUrlSocial(string $path): Item {
		$this->urlSocial = $path;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getUrlCloud(): string {
		return $this->urlCloud;
	}

	/**
	 * @param string $path
	 *
	 * @return Item
	 */
	public function setUrlCloud(string $path): Item {
		$this->urlCloud = $path;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getAddress(): string {
		return $this->address;
	}

	/**
	 * @param string $address
	 *
	 * @return Item
	 */
	public function setAddress(string $address) {
		$this->address = $address;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getTo(): string {
		return $this->to;
	}

	/**
	 * @param string $to
	 *
	 * @return Item
	 */
	public function setTo(string $to): Item {
		$this->to = $to;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getToArray(): array {
		return $this->toArray;
	}

	/**
	 * @param string $to
	 *
	 * @return Item
	 */
	public function addToArray(string $to): Item {
		$this->toArray[] = $to;

		return $this;
	}

	/**
	 * @param array $toArray
	 *
	 * @return Item
	 */
	public function setToArray(array $toArray): Item {
		$this->toArray = $toArray;

		return $this;
	}


	public function addCc(string $cc): Item {
		$this->cc[] = $cc;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getCcArray(): array {
		return $this->cc;
	}

	/**
	 * @param array $cc
	 *
	 * @return Item
	 */
	public function setCcArray(array $cc): Item {
		$this->cc = $cc;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getBccArray(): array {
		return $this->bcc;
	}

	/**
	 * @param array $bcc
	 *
	 * @return Item
	 */
	public function setBccArray(array $bcc): Item {
		$this->bcc = $bcc;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getOrigin(): string {
		return $this->origin;
	}

	/**
	 * @return int
	 */
	public function getOriginSource(): int {
		return $this->originSource;
	}

	/**
	 * @return int
	 */
	public function getOriginCreationTime(): int {
		return $this->originCreationTime;
	}


	/**
	 * @param string $origin
	 *
	 * @param int $source
	 *
	 * @param int $creationTime
	 *
	 * @return Item
	 */
	public function setOrigin(string $origin, int $source, int $creationTime): Item {
		$this->origin = $origin;
		$this->originSource = $source;
		$this->originCreationTime = $creationTime;

		return $this;
	}


	/**
	 * @param string $published
	 *
	 * @return Item
	 */
	public function setPublished(string $published): Item {
		$this->published = $published;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getPublished(): string {
		return $this->published;
	}


	/**
	 * @param array $tag
	 *
	 * @return Item
	 */
	public function addTag(array $tag): Item {
		$this->tags[] = $tag;

		return $this;
	}

	/**
	 * @param string $type
	 *
	 * @return array
	 */
	public function getTags(string $type = ''): array {
		if ($type === '') {
			return $this->tags;
		}

		$result = [];
		foreach ($this->tags as $tag) {
			if ($this->get('type', $tag, '') === $type) {
				$result[] = $tag;
			}
		}

		return $result;
	}

	/**
	 * @param array $tag
	 *
	 * @return Item
	 */
	public function setTags(array $tag): Item {
		$this->tags = $tag;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getObjectId(): string {
		return $this->objectId;
	}

	/**
	 * @param string $objectId
	 *
	 * @return Item
	 */
	public function setObjectId(string $objectId): Item {
		$this->objectId = $objectId;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getIconId(): string {
		return $this->iconId;
	}

	/**
	 * @param string $iconId
	 *
	 * @return Item
	 */
	public function setIconId(string $iconId): Item {
		$this->iconId = $iconId;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isLocal(): bool {
		return $this->local;
	}

	/**
	 * @param bool $local
	 *
	 * @return Person
	 */
	public function setLocal(bool $local): Item {
		$this->local = $local;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isCompleteDetails(): bool {
		return $this->completeDetails;
	}

	/**
	 * @param bool $completeDetails
	 *
	 * @return Item
	 */
	public function setCompleteDetails(bool $completeDetails): Item {
		$this->completeDetails = $completeDetails;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getSource(): string {
		return $this->source;
	}

	/**
	 * @param string $source
	 *
	 * @return Item
	 */
	public function setSource(string $source): Item {
		$this->source = $source;

		return $this;
	}


}


