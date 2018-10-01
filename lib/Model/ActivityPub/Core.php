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


use JsonSerializable;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\ICoreService;

class Core implements JsonSerializable {


	/** @var string */
	private $root = '';

	/** @var bool */
	private $isTopLevel = false;

	/** @var string */
	private $address = '';

	/** @var string */
	private $id = '';

	/** @var string */
	private $type;

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

	/** @var Actor */
	private $actor;

	/** @var array */
	private $tags = [];

	/** @var array */
	private $entries = [];

	/** @var Core */
	private $object = null;

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
	 * @return Core
	 */
	public function setId(string $id): Core {
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
	 * @return Core
	 */
	public function setType(string $type): Core {
		$this->type = $type;

		return $this;
	}


	/**
	 * @param InstancePath $instancePath
	 *
	 * @return Core
	 */
	public function addInstancePath(InstancePath $instancePath): Core {
		$this->instancePaths[] = $instancePath;

		return $this;
	}


	/**
	 * @param InstancePath[] $path
	 *
	 * @return Core
	 */
	public function addInstancePaths(array $path): Core {
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
	 * @return Core
	 */
	public function setInstancePaths(array $instancePaths): Core {
		$this->instancePaths = $instancePaths;

		return $this;
	}


	/**
	 * @return Actor
	 */
	public function getActor(): Actor {
		return $this->actor;
	}

	/**
	 * @param Actor $actor
	 *
	 * @return Core
	 */
	public function setActor(Actor $actor): Core {
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
	 * @return string
	 */
	public function getRoot(): string {
		return $this->root;
	}

	/**
	 * @param string $path
	 *
	 * @return Core
	 */
	public function setRoot(string $path): Core {
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
	 * @return Core
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
	 * @return Core
	 */
	public function setTo(string $to): Core {
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
	 * @return Core
	 */
	public function addToArray(string $to): Core {
		$this->toArray[] = $to;

		return $this;
	}


	/**
	 * @param array $toArray
	 *
	 * @return Core
	 */
	public function setToArray(array $toArray): Core {
		$this->toArray = $toArray;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getCc(): array {
		return $this->cc;
	}

	/**
	 * @param array $cc
	 *
	 * @return Core
	 */
	public function setCc(array $cc): Core {
		$this->cc = $cc;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getBcc(): array {
		return $this->bcc;
	}

	/**
	 * @param array $bcc
	 *
	 * @return Core
	 */
	public function setBcc(array $bcc): Core {
		$this->bcc = $bcc;

		return $this;
	}


	/**
	 * @param array $tag
	 *
	 * @return Core
	 */
	public function addTag(array $tag): Core {
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
	 * @return Core
	 */
	public function setTags(array $tag): Core {
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
	 * @return Core
	 */
	public function getObject(): Core {
		return $this->object;
	}

	/**
	 * @param Core $object
	 *
	 * @return Core
	 */
	public function setObject(Core $object): Core {
		$this->object = $object;

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
	 * @return Core
	 */
	public function setEntries(array $arr): Core {
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
	 * @return Core
	 */
	public function addEntry(string $k, string $v): Core {
		if ($v === '') {
			unset($this->entries[$k]);

			return $this;
		}

		$this->entries[$k] = $v;

		return $this;
	}


	/**
	 * @param string $k
	 * @param array $v
	 *
	 * @return Core
	 */
	public function addEntryArray(string $k, array $v): Core {
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
	 * @return array
	 */
	public function jsonSerialize(): array {
		$this->addEntry('id', $this->getId());
		$this->addEntry('type', $this->getType());
		$this->addEntry('url', $this->getId());

		if ($this->getToArray() === []) {
			$this->addEntry('to', $this->getTo());
		} else {
			$this->addEntryArray('to', $this->getToArray());
		}

		$this->addEntryArray('cc', $this->getCc());

		if ($this->gotActor()) {
			$this->addEntry(
				'actor', $this->getActor()
							  ->getId()
			);
		}

		if ($this->getTags() !== []) {
			$this->addEntryArray('tag', $this->getTags());
		}

		$arr = $this->getEntries();
		if ($this->gotObject()) {
			$arr['object'] = $this->getObject();
		}

		return $arr;
	}

}


