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


use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;


class Note extends Stream implements JsonSerializable {


	const TYPE = 'Note';


	/** @var array */
	private $hashtags = [];


	/**
	 * Note constructor.
	 *
	 * @param ACore $parent
	 */
	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}


	/**
	 * @return array
	 */
	public function getHashtags(): array {
		return $this->hashtags;
	}

	/**
	 * @param array $hashtags
	 *
	 * @return Note
	 */
	public function setHashtags(array $hashtags): Note {
		$this->hashtags = $hashtags;

		return $this;
	}

	/**
	 *
	 */
	public function fillHashtags() {
		$tags = $this->getTags('Hashtag');
		$hashtags = [];
		foreach ($tags as $tag) {
			$hashtag = $tag['name'];
			if (substr($hashtag, 0, 1) === '#') {
				$hashtag = substr($hashtag, 1);
			}
			$hashtags[] = $hashtag;
		}

		$this->setHashtags($hashtags);
	}


	/**
	 * @param array $data
	 */
	public function import(array $data) {
		parent::import($data);
	}


	/**
	 * @param array $data
	 */
	public function importFromDatabase(array $data) {
		parent::importFromDatabase($data);

		$this->setHashtags($this->getArray('hashtags', $data, []));
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		$result = parent::jsonSerialize();

		if ($this->isCompleteDetails()) {
			$result['hashtags'] = $this->getHashtags();
		}

		$this->cleanArray($result);

		return $result;
	}

}

