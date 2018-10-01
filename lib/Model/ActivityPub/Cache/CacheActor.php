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

namespace OCA\Social\Model\ActivityPub\Cache;


class CacheActor {

	/** @var int */
	private $id;

	/** @var string */
	private $account = '';

	/** @var string */
	private $url;

	/** @var array */
	private $actor = [];

	/** @var int */
	private $creation = 0;


	/**
	 * CacheActor constructor.
	 *
	 * @param int $id
	 */
	public function __construct($id = 0) {
		$this->id = $id;
	}


	/**
	 * @return int
	 */
	public function getId(): int {
		return $this->id;
	}

	/**
	 * @param int $id
	 *
	 * @return CacheActor
	 */
	public function setId(int $id): CacheActor {
		$this->account = $id;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getAccount(): string {
		return $this->account;
	}

	/**
	 * @param string $account
	 *
	 * @return CacheActor
	 */
	public function setAccount(string $account): CacheActor {
		$this->account = $account;

		return $this;
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
	 * @return CacheActor
	 */
	public function setUrl(string $url): CacheActor {
		$this->url = $url;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getActor(): array {
		return $this->actor;
	}

	/**
	 * @param array $actor
	 *
	 * @return CacheActor
	 */
	public function setActor(array $actor): CacheActor {
		$this->actor = $actor;

		return $this;
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
	 * @return CacheActor
	 */
	public function setCreation(int $creation): CacheActor {
		$this->creation = $creation;

		return $this;
	}


}

