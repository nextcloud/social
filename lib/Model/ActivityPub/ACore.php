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
use JsonSerializable;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\ICoreService;


abstract class ACore implements JsonSerializable {


	use TArrayTools;


	const CONTEXT_ACTIVITYSTREAMS = 'https://www.w3.org/ns/activitystreams';
	const CONTEXT_SECURITY = 'https://w3id.org/security/v1';


	/** @var string */
	private $root = '';

	/** @var bool */
	private $isTopLevel = false;

	/** @var array */
	private $meta = [];

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

	/** @var array */
	private $entries = [];

	/** @var Person */
	private $actor = null;

	/** @var string */
	private $actorId = '';

	/** @var ACore */
	private $object = null;

	/** @var string */
	private $objectId = '';

	/** @var ICoreService */
	private $saveAs;


	/**
	 * Core constructor.
	 *
	 * @param bool $isTopLevel
	 */
	public function __construct(bool $isTopLevel = false) {
		$this->isTopLevel = $isTopLevel;
	}


	/**
	 * @return string
	 */
	public function getId(): string {
		return $this->id;
	}


	/**
	 * @param string $id
	 *
	 * @return ACore
	 */
	public function setId(string $id): ACore {
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
	 * @return ACore
	 */
	public function setType(string $type): ACore {
		$this->type = $type;

		return $this;
	}


	/**
	 * @param string $meta
	 * @param string $value
	 *
	 * @return ACore
	 */
	public function addMeta(string $meta, string $value): ACore {
		$this->meta[$meta] = $value;

		return $this;
	}

	/**
	 * @param string $meta
	 * @param string $value
	 *
	 * @return ACore
	 */
	public function addMetaBool(string $meta, bool $value): ACore {
		$this->meta[$meta] = $value;

		return $this;
	}

	/**
	 * @param string $meta
	 *
	 * @return string
	 */
	public function getMeta(string $meta): string {
		return $this->get($meta, $this->meta, '');
	}

	/**
	 * @param string $meta
	 *
	 * @return bool
	 */
	public function getMetaBool(string $meta): bool {
		return $this->getBool($meta, $this->meta, false);
	}

	/**
	 * @param array $meta
	 *
	 * @return ACore
	 */
	public function setMetaAll(array $meta): ACore {
		$this->meta = $meta;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getMetaAll(): array {
		return $this->meta;
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
	 * @return ACore
	 */
	public function setUrl(string $url): ACore {
		$this->url = $url;

		return $this;
	}


	/**
	 * @param InstancePath $instancePath
	 *
	 * @return ACore
	 */
	public function addInstancePath(InstancePath $instancePath): ACore {
		$this->instancePaths[] = $instancePath;

		return $this;
	}


	/**
	 * @param InstancePath[] $path
	 *
	 * @return ACore
	 */
	public function addInstancePaths(array $path): ACore {
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
	 * @return ACore
	 */
	public function setInstancePaths(array $instancePaths): ACore {
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
	 * @return ACore
	 */
	public function setSummary(string $summary): ACore {
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
	 * @return ACore
	 */
	public function setActor(Person $actor): ACore {
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
	 * @return ACore
	 */
	public function setActorId(string $actorId): ACore {
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
	public function getRoot(): string {
		return $this->root;
	}

	/**
	 * @param string $path
	 *
	 * @return ACore
	 */
	public function setRoot(string $path): ACore {
		$this->root = $path;

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
	 * @return ACore
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
	 * @return ACore
	 */
	public function setTo(string $to): ACore {
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
	 * @return ACore
	 */
	public function addToArray(string $to): ACore {
		$this->toArray[] = $to;

		return $this;
	}

	/**
	 * @param array $toArray
	 *
	 * @return ACore
	 */
	public function setToArray(array $toArray): ACore {
		$this->toArray = $toArray;

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
	 * @return ACore
	 */
	public function setCcArray(array $cc): ACore {
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
	 * @return ACore
	 */
	public function setBccArray(array $bcc): ACore {
		$this->bcc = $bcc;

		return $this;
	}


	/**
	 * @param string $published
	 *
	 * @return ACore
	 */
	public function setPublished(string $published): ACore {
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
	 * @return ACore
	 */
	public function addTag(array $tag): ACore {
		$this->tags[] = $tag;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getTags(): array {
		return $this->tags;
	}

	/**
	 * @param array $tag
	 *
	 * @return ACore
	 */
	public function setTags(array $tag): ACore {
		$this->tags = $tag;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function gotObject(): bool {
		if ($this->object === null) {
			return false;
		}

		return true;
	}

	/**
	 * @return ACore
	 */
	public function getObject(): ACore {
		return $this->object;
	}

	/**
	 * @param ACore $object
	 *
	 * @return ACore
	 */
	public function setObject(ACore $object): ACore {
		$this->object = $object;

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
	 * @return ACore
	 */
	public function setObjectId(string $objectId): ACore {
		$this->objectId = $objectId;

		return $this;
	}


	/**
	 * @param bool $topLevel
	 *
	 * @return ACore
	 */
	public function setTopLevel(bool $topLevel): ACore {
		$this->isTopLevel = $topLevel;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isTopLevel(): bool {
		return $this->isTopLevel;
	}


	/**
	 * @param array $arr
	 *
	 * @return ACore
	 */
	public function setEntries(array $arr): ACore {
		$this->entries = $arr;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getEntries(): array {
		return $this->entries;
	}

	/**
	 * @param string $k
	 * @param string $v
	 *
	 * @return ACore
	 */
	public function addEntry(string $k, string $v): ACore {
		if ($v === '') {
//			unset($this->entries[$k]);

			return $this;
		}

		$this->entries[$k] = $v;

		return $this;
	}

	/**
	 * @param string $k
	 * @param array $v
	 *
	 * @return ACore
	 */
	public function addEntryArray(string $k, array $v): ACore {
		if ($v === []) {
//			unset($this->entries[$k]);

			return $this;
		}

		$this->entries[$k] = $v;

		return $this;
	}


	/**
	 * @param string $k
	 * @param ACore $v
	 *
	 * @return ACore
	 */
	public function addEntryItem(string $k, ACore $v): ACore {
		if ($v === null) {
//			unset($this->entries[$k]);

			return $this;
		}

		$this->entries[$k] = $v;

		return $this;
	}


	/**
	 * @param ICoreService $class
	 */
	public function saveAs(ICoreService $class) {
		$this->saveAs = $class;
	}

	/**
	 * @return ICoreService
	 */
	public function savingAs() {
		return $this->saveAs;
	}


	/**
	 * @param array $data
	 */
	public function import(array $data) {
		$this->setId($this->get('id', $data, ''));
		$this->setUrl($this->get('url', $data, ''));
		$this->setSummary($this->get('summary', $data, ''));
		$this->setToArray($this->getArray('to', $data, []));
		$this->setCcArray($this->getArray('cc', $data, []));
		$this->setPublished($this->get('published', $data, ''));
		$this->setActorId($this->get('actor', $data, ''));
		$this->setObjectId($this->get('object', $data, ''));
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {

		if ($this->isTopLevel()) {
			$this->addEntryArray(
				'@context', [
							  self::CONTEXT_ACTIVITYSTREAMS,
							  self::CONTEXT_SECURITY
						  ]
			);
		}

		$this->addEntry('id', $this->getId());
		$this->addEntry('type', $this->getType());
		$this->addEntry('url', $this->getId());

		$this->addEntry('to', $this->getTo());
		$this->addEntryArray('to', $this->getToArray());
		$this->addEntryArray('cc', $this->getCcArray());

		if ($this->gotActor()) {
			$this->addEntry(
				'actor', $this->getActor()
							  ->getId()
			);
		} else {
			$this->addEntry('actor', $this->getActorId());
		}

		$this->addEntry('summary', $this->getSummary());
		$this->addEntry('published', $this->getPublished());
		$this->addEntryArray('tag', $this->getTags());

//		$arr = $this->getEntries();
		if ($this->gotObject()) {
			$this->addEntryItem('object', $this->getObject());
		} else {
			$this->addEntry('object', $this->getObjectId());
		}

		return $this->getEntries();
	}

}


