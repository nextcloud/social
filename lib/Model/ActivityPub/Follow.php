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
use OCA\Social\Exceptions\InvalidResourceEntryException;


/**
 * Class Follow
 *
 * @package OCA\Social\Model\ActivityPub
 */
class Follow extends ACore implements JsonSerializable {


	const TYPE = 'Follow';


	/** @var string */
	private $followId = '';

	/** @var bool */
	private $accepted = false;


	/**
	 * Follow constructor.
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
	public function getFollowId(): string {
		return $this->followId;
	}

	/**
	 * @param string $followId
	 *
	 * @return Follow
	 */
	public function setFollowId(string $followId): Follow {
		$this->followId = $followId;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isAccepted(): bool {
		return $this->accepted;
	}

	/**
	 * @param bool $accepted
	 *
	 * @return Follow
	 */
	public function setAccepted(bool $accepted): Follow {
		$this->accepted = $accepted;

		return $this;
	}


	/**
	 * @param array $data
	 *
	 * @throws InvalidResourceEntryException
	 */
	public function import(array $data) {
		parent::import($data);
	}


	/**
	 * @param array $data
	 */
	public function importFromDatabase(array $data) {
		parent::importFromDatabase($data);

		$this->setAccepted(($this->getInt('accepted', $data, 0) === 1) ? true : false);
		$this->setFollowId($this->get('follow_id', $data, ''));
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return array_merge(
			parent::jsonSerialize(),
			[
				'follow_id' => $this->getFollowId(),
				'accepted'  => $this->isAccepted()
			]
		);
	}

}

