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
class ClientAuth implements IQueryRow, JsonSerializable {


	use TArrayTools;


	/** @var int */
	private $id = 0;

	/** @var int */
	private $clientId = 0;

	/** @var string */
	private $redirectUri = '';

	/** @var string */
	private $code = '';

	/** @var string */
	private $userId = '';

	/** @var string */
	private $account = '';

	/** @var array */
	private $scopes = [];

	/** @var ClientToken */
	private $clientToken;

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
	 * @return ClientAuth
	 */
	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getRedirectUri(): string {
		return $this->redirectUri;
	}

	/**
	 * @param string $redirectUri
	 *
	 * @return ClientAuth
	 */
	public function setRedirectUri(string $redirectUri): self {
		$this->redirectUri = $redirectUri;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getCode(): string {
		return $this->code;
	}

	/**
	 * @param string $code
	 *
	 * @return ClientAuth
	 */
	public function setCode(string $code): self {
		$this->code = $code;

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
	 * @return ClientAuth
	 */
	public function setAccount(string $account): self {
		$this->account = $account;

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
	 * @return ClientAuth
	 */
	public function setUserId(string $userId): self {
		$this->userId = $userId;

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
	 * @return ClientAuth
	 */
	public function setScopes(array $scopes): self {
		$this->scopes = $scopes;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getClientId(): int {
		return $this->clientId;
	}

	/**
	 * @param int $clientId
	 *
	 * @return ClientAuth
	 */
	public function setClientId(int $clientId): self {
		$this->clientId = $clientId;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function hasClientToken(): bool {
		return ($this->clientToken !== null);
	}

	/**
	 * @param ClientToken $clientToken
	 *
	 * @return ClientAuth
	 */
	public function setClientToken(ClientToken $clientToken): self {
		$this->clientToken = $clientToken;

		return $this;
	}

	/**
	 * @return ClientToken
	 */
	public function getClientToken(): ClientToken {
		return $this->clientToken;
	}


	/**
	 * @param array $data
	 *
	 * @return ClientAuth
	 */
	public function importFromDatabase(array $data): self {
		$this->setId($this->getInt('id', $data));
		$this->setClientId($this->getInt('client_id', $data));
		$this->setScopes($this->getArray('scopes', $data));
		$this->setAccount($this->get('account', $data));
		$this->setUserId($this->get('user_id', $data));
		$this->setCode($this->get('code', $data));
		$this->setRedirectUri($this->get('redirect_uri', $data));

		return $this;
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		$arr = [
			'id'           => $this->getId(),
			'client_id'    => $this->getClientId(),
			'scopes'       => $this->getScopes(),
			'account'      => $this->getAccount(),
			'user_id'      => $this->getUserId(),
			'code'         => $this->getCode(),
			'redirect_uri' => $this->getRedirectUri(),
		];

		if ($this->hasClientToken()) {
			$arr['client_token'] = $this->getClientToken();
		}

		return array_filter($arr);
	}

}

