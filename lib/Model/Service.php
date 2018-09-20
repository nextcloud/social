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

namespace OCA\Social\Model;


use daita\Traits\TArrayTools;

class Service implements \JsonSerializable {


	const STATUS_SETUP = 0;
	const STATUS_VALID = 1;


	use TArrayTools;


	/** @var int */
	private $id;

	/** @var string */
	private $type = '';

	/** @var string */
	private $address = '';

	/** @var int */
	private $status = -1;

	/** @var array */
	private $config = [];

	/** @var int */
	private $creation = 0;


	/**
	 * Service constructor.
	 *
	 * @param int $id
	 */
	public function __construct(int $id = 0) {
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
	 * @return Service
	 */
	public function setId(int $id): Service {
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
	 * @return Service
	 */
	public function setType(string $type): Service {
		$this->type = $type;

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
	 * @return Service
	 */
	public function setAddress(string $address): Service {
		$this->address = $address;

		return $this;
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
	 * @return Service
	 */
	public function setStatus(int $status): Service {
		$this->status = $status;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getConfigAll(): array {
		return $this->config;
	}

	/**
	 * @param array $config
	 *
	 * @return Service
	 */
	public function setConfigAll(array $config): Service {
		$this->config = $config;

		return $this;
	}


	/**
	 * @param $key
	 * @param string $default
	 *
	 * @return string
	 */
	public function getConfig(string $key, string $default = ''): string {
		return $this->get($key, $this->config, $default);
	}

	/**
	 * @param string $key
	 * @param string $value
	 *
	 * @return Service
	 */
	public function setConfig(string $key, string $value): Service {
		$this->config[$key] = $value;

		return $this;
	}

	/**
	 * @param string $key
	 *
	 * @return Service
	 */
	public function unsetConfig(string $key): Service {
		unset($this->config[$key]);

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
	 * @return Service
	 */
	public function setCreation(int $creation): Service {
		$this->creation = $creation;

		return $this;
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'id'       => $this->getId(),
			'type'     => $this->getType(),
			'address'  => $this->getAddress(),
			'status'   => $this->getStatus(),
			'config'   => $this->getConfigAll(),
			'creation' => $this->getCreation()
		];
	}


}

