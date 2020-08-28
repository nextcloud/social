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


namespace OCA\Social\Model\Client;


use daita\MySmallPhpTools\IQueryRow;
use daita\MySmallPhpTools\Traits\TArrayTools;
use JsonSerializable;


/**
 * Class ClientApp
 *
 * @package OCA\Social\Model\Client
 */
class ClientToken implements IQueryRow, JsonSerializable {


	use TArrayTools;


	/** @var int */
	private $id = 0;

	/** @var int */
	private $authId = 0;

	/** @var string */
	private $token = '';

	/** @var array */
	private $scopes = [];

	/** @var int */
	private $lastUpdate = 0;

	/** @var int */
	private $creation = 0;


	/**
	 * ClientApp constructor.
	 */
	public function __construct() {
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
	 * @return ClientToken
	 */
	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getAuthId(): int {
		return $this->authId;
	}

	/**
	 * @param int $authId
	 *
	 * @return ClientToken
	 */
	public function setAuthId(int $authId): self {
		$this->authId = $authId;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getToken(): string {
		return $this->token;
	}

	/**
	 * @param string $token
	 *
	 * @return ClientToken
	 */
	public function setToken(string $token): self {
		$this->token = $token;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getScopes(): array {
		return $this->scopes;
	}

	/**
	 * @param array $scopes
	 *
	 * @return ClientToken
	 */
	public function setScopes(array $scopes): self {
		$this->scopes = $scopes;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getLastUpdate(): int {
		return $this->lastUpdate;
	}

	/**
	 * @param int $lastUpdate
	 */
	public function setLastUpdate(int $lastUpdate): void {
		$this->lastUpdate = $lastUpdate;
	}


	/**
	 * @return int
	 */
	public function getCreation(): int {
		return $this->creation;
	}

	/**
	 * @param int $creation
	 */
	public function setCreation(int $creation): void {
		$this->creation = $creation;
	}

	/**
	 * @param array $data
	 *
	 * @return ClientToken
	 */
	public function importFromDatabase(array $data): self {
		$this->setId($this->getInt('id', $data));
		$this->setAuthId($this->getInt('auth_id', $data));
		$this->setToken($this->get('token', $data));
		$this->setScopes($this->getArray('scopes', $data));
		$this->setLastUpdate($this->getInt('last_update', $data));
		$this->setCreation($this->getInt('creation', $data));

		return $this;
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		$arr = [
			'id'          => $this->getId(),
			'auth_id'     => $this->getAuthId(),
			'token'       => $this->getToken(),
			'scopes'      => $this->getScopes(),
			'last_update' => $this->getLastUpdate(),
			'creation'    => $this->getCreation()
		];

		return array_filter($arr);
	}

}

