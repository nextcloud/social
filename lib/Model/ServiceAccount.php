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


class ServiceAccount implements \JsonSerializable {


	/** @var int */
	private $id;

	/** @var Service */
	private $service;

	/** @var string */
	private $userId;

	/** @var string */
	private $account = '';

	/** @var int */
	private $status = 0;

	/** @var array */
	private $auth = [];

	/** @var array */
	private $config = [];

	/** @var int */
	private $creation = 0;


	/**
	 * ServiceAccount constructor.
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
	 * @return ServiceAccount
	 */
	public function setId(int $id): ServiceAccount {
		$this->id = $id;

		return $this;
	}


	/**
	 * @return Service
	 */
	public function getService(): Service {
		return $this->service;
	}

	/**
	 * @param Service $service
	 *
	 * @return ServiceAccount
	 */
	public function setService(Service $service): ServiceAccount {
		$this->service = $service;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getUserId(): string {
		return $this->userId;
	}

	/**
	 * @param string $userId
	 *
	 * @return ServiceAccount
	 */
	public function setUserId(string $userId): ServiceAccount {
		$this->userId = $userId;

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
	 * @return ServiceAccount
	 */
	public function setAccount(string $account): ServiceAccount {
		$this->account = $account;

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
	 * @return ServiceAccount
	 */
	public function setStatus(int $status): ServiceAccount {
		$this->status = $status;

		return $this;
	}


	/**
	 * @param string $k
	 *
	 * @return string
	 */
	public function getAuth(string $k): string {
		return $this->auth[$k];
	}

	/**
	 * @param string $k
	 *
	 * @return int
	 */
	public function getAuthInt(string $k): int {
		return $this->auth[$k];
	}


	/**
	 * @return array
	 */
	public function getAuthAll(): array {
		return $this->auth;
	}

	/**
	 * @param array $auth
	 *
	 * @return ServiceAccount
	 */
	public function setAuthAll(array $auth): ServiceAccount {
		$this->auth = $auth;

		return $this;
	}

	/**
	 * @param string $k
	 * @param string $v
	 *
	 * @return ServiceAccount
	 */
	public function setAuth(string $k, string $v): ServiceAccount {
		$this->auth[$k] = $v;

		return $this;
	}

	/**
	 * @param string $k
	 * @param int $v
	 *
	 * @return ServiceAccount
	 */
	public function setAuthInt(string $k, int $v): ServiceAccount {
		$this->auth[$k] = $v;

		return $this;
	}


	/**
	 * @param string $k
	 *
	 * @return string
	 */
	public function getConfig(string $k): string {
		return $this->config[$k];
	}

	/**
	 * @param string $k
	 *
	 * @return int
	 */
	public function getConfigInt(string $k): int {
		return $this->config[$k];
	}

	/**
	 * @return array
	 */
	public function getConfigAll(): array {
		return $this->config;
	}


	/**
	 * @param string $k
	 * @param string $v
	 *
	 * @return ServiceAccount
	 */
	public function setConfig(string $k, string $v): ServiceAccount {
		$this->config[$k] = $v;

		return $this;
	}

	/**
	 * @param string $k
	 * @param int $v
	 *
	 * @return ServiceAccount
	 */
	public function setConfigInt(string $k, int $v): ServiceAccount {
		$this->config[$k] = $v;

		return $this;
	}

	/**
	 * @param array $config
	 *
	 * @return ServiceAccount
	 */
	public function setConfigAll(array $config): ServiceAccount {
		$this->config = $config;

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
	 * @return ServiceAccount
	 */
	public function setCreation(int $creation): ServiceAccount {
		$this->creation = $creation;

		return $this;
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'id'       => $this->getId(),
			'service'  => $this->getService(),
			'userId'   => $this->getUserId(),
			'account'  => $this->getAccount(),
			'auth'     => $this->getAuthAll(),
			'creation' => $this->getCreation()
		];
	}


}

