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


/**
 * Class OrderedCollection
 *
 * @package OCA\Social\Model\ActivityPub
 */
class OrderedCollection extends ACore implements JsonSerializable {


	const TYPE = 'OrderedCollection';


	/** @var int */
	private $totalItems = 0;

	/** @var string */
	private $first = '';

	/**
	 * Activity constructor.
	 *
	 * @param ACore $parent
	 */
	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}


	/**
	 * @return int
	 */
	public function getTotalItems(): int {
		return $this->totalItems;
	}

	/**
	 * @param int $totalItems
	 *
	 * @return OrderedCollection
	 */
	public function setTotalItems(int $totalItems): OrderedCollection {
		$this->totalItems = $totalItems;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getFirst(): string {
		return $this->first;
	}

	/**
	 * @param string $first
	 *
	 * @return OrderedCollection
	 */
	public function setFirst(string $first): OrderedCollection {
		$this->first = $first;

		return $this;
	}







//"id": "https://pub.pontapreta.net/users/admin/following",
//"type": "OrderedCollection",
//"totalItems": 1,
//"first": "https://pub.pontapreta.net/users/admin/following?page=1"


	/**
	 * @param array $data
	 */
	public function import(array $data) {
		parent::import($data);
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return array_merge(
			parent::jsonSerialize(),
			[
				'totalItems' => $this->getTotalItems(),
				'first'      => $this->getFirst()
			]
		);
	}

}

