<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\InstancePath;
use OCA\Social\Tools\Traits\TArrayTools;

class Item {
	use TArrayTools;

	private string $urlSocial = '';
	private string $urlCloud = '';
	private string $address = '';
	private string $id = '';
	private int $nid = 0;
	private string $type = '';
	private string $subType = '';
	private string $url = '';
	private string $attributedTo = '';
	private string $summary = '';
	/** @var InstancePath[] */
	private array $instancePaths = [];
	private string $to = '';
	private array $toArray = [];
	private array $cc = [];
	private array $bcc = [];
	private string $published = '';
	private array $tags = [];
	private ?Person $actor = null;
	private string $actorId = '';
	private string $iconId = '';
	private string $objectId = '';
	private string $target = '';
	private bool $completeDetails = false;
	private string $source = '';
	private bool $local = false;
	private string $origin = '';
	private int $originSource = 0;
	private int $originCreationTime = 0;

	public function getId(): string {
		return $this->id;
	}

	public function setId(string $id): Item {
		$this->id = $id;

		return $this;
	}

	public function getNid(): int {
		return $this->nid;
	}

	public function setNid(int $nid): self {
		$this->nid = $nid;

		return $this;
	}

	public function getType(): string {
		return $this->type;
	}

	public function setType(string $type): Item {
		$this->type = $type;

		return $this;
	}

	public function getSubType(): string {
		return $this->subType;
	}

	public function setSubType(string $type): Item {
		$this->subType = $type;

		return $this;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function setUrl(string $url): Item {
		$this->url = $url;

		return $this;
	}

	public function addInstancePath(InstancePath $instancePath): Item {
		if ($instancePath->getUri() !== '') {
			$this->instancePaths[] = $instancePath;
		}

		return $this;
	}

	/**
	 * @param InstancePath[] $path
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
	public function getActor(): ?Person {
		return $this->actor;
	}

	public function setActor(Person $actor): Item {
		$this->actor = $actor;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function hasActor(): bool {
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
		if ($this->hasActor()) {
			return $this->getActor()
				->getId();
		}

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
	 * @return array
	 */
	public function getToAll(): array {
		$arr = $this->toArray;
		$arr[] = $this->to;

		return $arr;
	}

	/**
	 * @param string $to
	 *
	 * @return Item
	 */
	public function addToArray(string $to): Item {
		if (!in_array($to, $this->toArray)) {
			$this->toArray[] = $to;
		}

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


	/**
	 * @param string $cc
	 *
	 * @return Item
	 */
	public function addCc(string $cc): Item {
		if (!$this->hasCc($cc)) {
			$this->cc[] = $cc;
		}

		return $this;
	}

	/**
	 * @param string $cc
	 *
	 * @return Item
	 */
	public function removeCc(string $cc): Item {
		if (!in_array($cc, $this->cc)) {
			return $this;
		}

		$this->cc = array_diff($this->cc, [$cc]);

		return $this;
	}

	/**
	 * @param string $cc
	 *
	 * @return bool
	 */
	public function hasCc(string $cc): bool {
		return (in_array($cc, $this->cc));
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

	public function getTarget(): string {
		return $this->target;
	}

	public function setTarget(string $target): Item {
		$this->target = $target;

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
