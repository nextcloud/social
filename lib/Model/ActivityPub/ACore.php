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
use daita\MySmallPhpTools\Traits\TPathTools;
use JsonSerializable;
use OCA\Social\Exceptions\ActivityCantBeVerifiedException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Service\ActivityPub\ICoreService;


abstract class ACore extends Item implements JsonSerializable {


	use TArrayTools;
	use TPathTools;


	const CONTEXT_ACTIVITYSTREAMS = 'https://www.w3.org/ns/activitystreams';
	const CONTEXT_SECURITY = 'https://w3id.org/security/v1';

	const AS_ID = 1;
	const AS_TYPE = 2;
	const AS_URL = 3;
	const AS_DATE = 4;
	const AS_USERNAME = 5;
	const AS_ACCOUNT = 6;
	const AS_STRING = 7;


	/** @var null Item */
	private $parent = null;

	/** @var array */
	private $entries = [];

	/** @var ACore */
	private $object = null;

	/** @var ICoreService */
	private $saveAs;

	/**
	 * Core constructor.
	 *
	 * @param ACore $parent
	 */
	public function __construct($parent = null) {
		if ($parent instanceof ACore) {
			$this->setParent($parent);
		}
	}


	/**
	 * @param Item $parent
	 *
	 * @return Item
	 */
	public function setParent(Item $parent): ACore {
		$this->parent = $parent;

		return $this;
	}

	/**
	 * @return Item
	 */
	public function getParent(): ACore {
		return $this->parent;
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
	 * @param string $base
	 *
	 * @throws UrlCloudException
	 */
	public function generateUniqueId(string $base = '') {
		if ($this->getUrlCloud() === '') {
			throw new UrlCloudException();
		}

		if ($base !== '') {
			$base = $this->withoutEndSlash($this->withBeginSlash($base));
		}

		$uuid = sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff),
			mt_rand(0, 0xffff), mt_rand(0, 0xfff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
			mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
		);

		$this->setId($this->getUrlCloud() . $base . '/' . $uuid);
	}


	/**
	 * @param $id
	 *
	 * @throws InvalidOriginException
	 */
	public function checkOrigin($id) {
		// TODO - compare with verify
		$host = parse_url($id, PHP_URL_HOST);
		if ($this->getRoot()
				 ->getOrigin() === $host) {
			return;
		}

		throw new InvalidOriginException();
	}


	/**
	 * @deprecated
	 *
	 * @param string $url
	 *
	 * @throws ActivityCantBeVerifiedException
	 */
	public function verify(string $url) {
		// TODO - Compare this with checkOrigin()
		$url1 = parse_url($this->getId());
		$url2 = parse_url($url);

		if ($this->get('host', $url1, '1') !== $this->get('host', $url2, '2')) {
			throw new ActivityCantBeVerifiedException('activity cannot be verified');
		}

		if ($this->get('scheme', $url1, '1') !== $this->get('scheme', $url2, '2')) {
			throw new ActivityCantBeVerifiedException('activity cannot be verified');
		}

		if ($this->getInt('port', $url1, 1) !== $this->getInt('port', $url2, 1)) {
			throw new ActivityCantBeVerifiedException('activity cannot be verified');
		}
	}


	/**
	 * @return bool
	 */
	public function isRoot(): bool {
		return ($this->parent === null);
	}

	/**
	 * @param array $chain
	 *
	 * @return ACore
	 */
	public function getRoot(array &$chain = []): ACore {
		$chain[] = $this;
		if ($this->isRoot()) {
			return $this;
		}

		return $this->getParent()
					->getRoot($chain);
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
	 * @param int $v
	 *
	 * @return ACore
	 */
	public function addEntryInt(string $k, int $v): ACore {
		if ($v === 0) {
			return $this;
		}

		$this->entries[$k] = $v;

		return $this;
	}

	/**
	 * @param string $k
	 * @param bool $v
	 *
	 * @return ACore
	 */
	public function addEntryBool(string $k, bool $v): ACore {
		if ($v === 0) {
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
	 * @param int $as
	 * @param string $k
	 * @param array $arr
	 * @param string $default
	 *
	 * @return string
	 */
	public function validate(int $as, string $k, array $arr, string $default = ''): string {
		$value = $this->validateEntryString($as, $this->get($k, $arr, $default));


		return $value;
	}


	/**
	 * @param int $as
	 * @param string $k
	 * @param array $arr
	 * @param array $default
	 *
	 * @return array
	 */
	public function validateArray(int $as, string $k, array $arr, array $default = []): array {
		$values = $this->getArray($k, $arr, $default);

		$result = [];
		foreach ($values as $value) {
			$result[] = $this->validateEntryString($as, $value);
		}

		return $result;
	}


	/**
	 * @param $as
	 * @param $value
	 *
	 * @return string
	 */
	public function validateEntryString(int $as, string $value): string {
		switch ($as) {
			case self::AS_ID:
				// TODO check if id looks valid or Exception
				break;

			case self::AS_TYPE:
				// TODO check if type looks valid or Exception
				break;

			case self::AS_URL:
				// TODO check if url looks valid or Exception
				break;

			case self::AS_DATE:
				// TODO check that date is valid
				break;

			case self::AS_STRING:
				// Clean string
				break;

			default:
				// exception
				break;
		}

		return $value;
	}


	/**
	 * @param array $data
	 */
	public function import(array $data) {
		$this->setId($this->validate(self::AS_ID, 'id', $data, ''));
		$this->setType($this->validate(self::AS_TYPE, 'type', $data, ''));
		$this->setUrl($this->validate(self::AS_URL, 'url', $data, ''));
		$this->setSummary($this->validate(self::AS_STRING, 'summary', $data, ''));
		$this->setToArray($this->validateArray(self::AS_ID, 'to', $data, []));
		$this->setCcArray($this->validateArray(self::AS_ID, 'cc', $data, []));
		$this->setPublished($this->validate(self::AS_DATE, 'published', $data, ''));
		$this->setActorId($this->validate(self::AS_ID, 'actor', $data, ''));
		$this->setObjectId($this->validate(self::AS_ID, 'object', $data, ''));
	}


	/**
	 * @param array $data
	 */
	public function importFromDatabase(array $data) {
		$this->setId($this->get('id', $data, ''));
		$this->setType($this->get('type', $data, ''));
		$this->setUrl($this->get('url', $data, ''));
		$this->setSummary($this->get('summary', $data, ''));
		$this->setToArray($this->getArray('to', $data, []));
		$this->setCcArray($this->getArray('cc', $data, []));
		$this->setPublished($this->get('published', $data, ''));
		$this->setActorId($this->get('actor_id', $data, ''));
		$this->setObjectId($this->get('object_id', $data, ''));
		$this->setSource($this->get('source', $data, ''));
		$this->setLocal(($this->getInt('local', $data, 0) === 1));
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		if ($this->isRoot()) {
			$this->addEntryArray(
				'@context', [
							  self::CONTEXT_ACTIVITYSTREAMS,
							  self::CONTEXT_SECURITY
						  ]
			);
		}

		$this->addEntry('id', $this->getId());
		$this->addEntry('type', $this->getType());
		$this->addEntry('url', $this->getUrl());

		$this->addEntry('to', $this->getTo());
		$this->addEntryArray('to', $this->getToArray());
		$this->addEntryArray('cc', $this->getCcArray());

		if ($this->gotActor()) {
			$this->addEntry(
				'actor', $this->getActor()
							  ->getId()
			);
			if ($this->isCompleteDetails()) {
				$this->addEntryItem('actor_info', $this->getActor());
			}
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

		if ($this->gotIcon()) {
			$this->addEntryItem('icon', $this->getIcon());
		}

		if ($this->isCompleteDetails()) {
			$this->addEntry('source', $this->getSource());
		}

		if ($this->isLocal()) {
			$this->addEntryBool('local', $this->isLocal());
		}

		return $this->getEntries();
	}

}


