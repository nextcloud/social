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

namespace OCA\Social\Tools\Model;

use OCA\Social\Tools\Exceptions\CacheItemNotFoundException;
use OCA\Social\Tools\Traits\TArrayTools;
use JsonSerializable;

/**
 * Class Cache
 *
 * @package OCA\Social\Tools\Model
 */
class Cache implements JsonSerializable {
	use TArrayTools;


	/** @var CacheItem[] */
	private $items = [];


	public function __construct() {
	}


	/**
	 * @return bool
	 */
	public function hasItems(): bool {
		return !empty($this->items);
	}

	/**
	 * @return CacheItem[]
	 */
	public function getItems(): array {
		return $this->items;
	}

	/**
	 * @param CacheItem[] $items
	 *
	 * @return Cache
	 */
	public function setItems(array $items): Cache {
		$this->items = $items;

		return $this;
	}

	/**
	 * @param CacheItem $item
	 *
	 * @return Cache
	 */
	public function addItem(CacheItem $item): Cache {
		if ($item->getUrl() === '' || $this->hasItem($item->getUrl())) {
			return $this;
		}

		$this->items[] = $item;

		return $this;
	}


	/**
	 * @param string $url
	 *
	 * @return CacheItem
	 * @throws CacheItemNotFoundException
	 */
	public function getItem(string $url): CacheItem {
		foreach ($this->getItems() as $item) {
			if ($item->getUrl() === $url) {
				return $item;
			}
		}

		throw new CacheItemNotFoundException();
	}


	/**
	 * @param string $url
	 *
	 * @return bool
	 */
	public function hasItem(string $url): bool {
		try {
			$this->getItem($url);

			return true;
		} catch (CacheItemNotFoundException $e) {
			return false;
		}
	}


	/**
	 * @param string $url
	 *
	 * @return Cache
	 */
	public function removeItem(string $url): Cache {
		$new = [];
		foreach ($this->getItems() as $item) {
			if ($item->getUrl() !== $url) {
				$new[] = $item;
			}
		}

		$this->items = $new;

		return $this;
	}

	/**
	 * $create can be false if we don't create item if it is not already in the list.
	 *
	 * @param CacheItem $cacheItem
	 * @param bool $create
	 *
	 * @return Cache
	 */
	public function updateItem(CacheItem $cacheItem, bool $create = true): Cache {
		if ($cacheItem->getUrl() === '') {
			return $this;
		}

		$new = [];
		$updated = false;
		foreach ($this->getItems() as $item) {
			if ($item->getUrl() === $cacheItem->getUrl()) {
				$updated = true;
				$new[] = $cacheItem;
			} else {
				$new[] = $item;
			}
		}

		if (!$updated && !$create) {
			$new[] = $cacheItem;
		}

		$this->items = $new;

		return $this;
	}


	/**
	 * @param array $data
	 */
	public function import(array $data) {
		$items = $this->getArray('_items', $data, []);

		foreach ($items as $entry) {
			$item = new CacheItem($entry);
			if (!array_key_exists($entry, $data)) {
				continue;
			}

			$item->import($data[$entry]);
			$this->addItem($item);
		}
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		$ids = array_map(
			function (CacheItem $item) {
				return $item->getUrl();
			}, $this->getItems()
		);

		$result = [
			'_items' => $ids,
			'_count' => count($ids)
		];

		foreach ($this->getItems() as $item) {
			$result[$item->getUrl()] = $item;
		}

		return $result;
	}
}
