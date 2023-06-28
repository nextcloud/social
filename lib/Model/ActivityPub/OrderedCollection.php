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

class OrderedCollection extends ACore implements JsonSerializable {
	public const TYPE = 'OrderedCollection';

	private int $totalItems = 0;
	private string $first = '';
	private string $last = '';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	public function getTotalItems(): int {
		return $this->totalItems;
	}

	public function setTotalItems(int $totalItems): self {
		$this->totalItems = $totalItems;

		return $this;
	}

	public function getFirst(): string {
		return $this->first;
	}

	public function setFirst(string $first): self {
		$this->first = $first;

		return $this;
	}

	public function getLast(): string {
		return $this->last;
	}

	public function setLast(string $last): self {
		$this->last = $last;

		return $this;
	}

	public function import(array $data): self {
		parent::import($data);
		$this->setFirst($this->validate(ACore::AS_USERNAME, 'first', $data, ''))
			 ->setLast($this->validate(ACore::AS_USERNAME, 'last', $data, ''))
			 ->setTotalItems($this->getInt('totalItems', $data));

		return $this;
	}

	public function jsonSerialize(): array {
		return array_filter(
			array_merge(
				parent::jsonSerialize(),
				[
					'totalItems' => $this->getTotalItems(),
					'first' => $this->getFirst(),
					'last' => $this->getLast()
				]
			)
		);
	}
}
