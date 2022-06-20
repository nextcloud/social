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

use OCA\Social\Tools\Traits\TArrayTools;
use JsonSerializable;

/**
 * Class CacheItem
 *
 * @package OCA\Social\Tools\Model
 */
class CacheItem implements JsonSerializable {
	use TArrayTools;


	/** @var string */
	private $url = '';

	/** @var string */
	private $content = '';

	/** @var int */
	private $status = 0;

	/** @var int */
	private $error = 0;

	/** @var int */
	private $creation = 0;


	/**
	 * CacheItem constructor.
	 *
	 * @param string $url
	 */
	public function __construct(string $url) {
		$this->url = $url;
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
	 * @return CacheItem
	 */
	public function setUrl(string $url): CacheItem {
		$this->url = $url;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getContent(): string {
		return $this->content;
	}

	/**
	 * @param string $content
	 *
	 * @return CacheItem
	 */
	public function setContent(string $content): CacheItem {
		$this->content = $content;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getObject(): array {
		$arr = json_decode($this->content, true);

		if (is_array($arr)) {
			return $arr;
		}

		return [];
	}


	/**
	 * @return int
	 */
	public function getStatus(): int {
		return $this->status;
	}

	/**
	 * @param int $status
	 *
	 * @return CacheItem
	 */
	public function setStatus(int $status): CacheItem {
		$this->status = $status;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getError(): int {
		return $this->error;
	}

	/**
	 * @return CacheItem
	 */
	public function incrementError(): CacheItem {
		$this->error++;

		return $this;
	}

	/**
	 * @param int $error
	 */
	public function setError(int $error) {
		$this->error = $error;
	}


	/**
	 * @return int
	 */
	public function getCreation(): int {
		return $this->creation;
	}

	/**
	 * @param int $creation
	 *
	 * @return CacheItem
	 */
	public function setCreation(int $creation): CacheItem {
		$this->creation = $creation;

		return $this;
	}


	/**
	 * @param array $data
	 */
	public function import(array $data) {
		$this->setUrl($this->get('url', $data, ''));
		$this->setContent($this->get('content', $data, ''));
		$this->setStatus($this->getInt('status', $data, 0));
		$this->setError($this->getInt('error', $data, 0));
		$this->setCreation($this->getInt('creation', $data, 0));
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'url' => $this->getUrl(),
			'content' => $this->getContent(),
			'object' => $this->getObject(),
			'status' => $this->getStatus(),
			'error' => $this->getError(),
			'creation' => $this->getCreation()
		];
	}
}
