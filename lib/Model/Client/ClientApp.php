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
class ClientApp implements IQueryRow, JsonSerializable {


	use TArrayTools;


	/** @var int */
	private $id = 0;

	/** @var string */
	private $name = '';

	/** @var string */
	private $website = '';

	/** @var array */
	private $redirectUris = [];

	/** @var array */
	private $scopes = [];

	/** @var string */
	private $clientId = '';

	/** @var string */
	private $clientSecret = '';


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
	 * @return ClientApp
	 */
	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * @param string $name
	 *
	 * @return ClientApp
	 */
	public function setName(string $name): self {
		$this->name = $name;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getWebsite(): string {
		return $this->website;
	}

	/**
	 * @param string $website
	 *
	 * @return ClientApp
	 */
	public function setWebsite(string $website): self {
		$this->website = $website;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getRedirectUris(): array {
		return $this->redirectUris;
	}

	/**
	 * @param array $redirectUris
	 *
	 * @return ClientApp
	 */
	public function setRedirectUris(array $redirectUris): self {
		$this->redirectUris = $redirectUris;

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
	 * @return ClientApp
	 */
	public function setScopes(array $scopes): self {
		$this->scopes = $scopes;

		return $this;
	}

	/**
	 * @param string $scopes
	 *
	 * @return ClientApp
	 */
	public function setScopesFromString(string $scopes): self {
		$this->scopes = explode(' ', $scopes);

		return $this;
	}


	/**
	 * @return string
	 */
	public function getClientId(): string {
		return $this->clientId;
	}

	/**
	 * @param string $clientId
	 *
	 * @return ClientApp
	 */
	public function setClientId(string $clientId): self {
		$this->clientId = $clientId;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getClientSecret(): string {
		return $this->clientSecret;
	}

	/**
	 * @param string $clientSecret
	 *
	 * @return ClientApp
	 */
	public function setClientSecret(string $clientSecret): self {
		$this->clientSecret = $clientSecret;

		return $this;
	}


	/**
	 * @param array $data
	 *
	 * @return ClientApp
	 */
	public function importFromDatabase(array $data): self {
		$this->setId($this->getInt('id', $data));
		$this->setName($this->get('name', $data));
		$this->setWebsite($this->get('website', $data));
		$this->setRedirectUris($this->getArray('redirect_uris', $data));
		$this->setScopes($this->getArray('scopes', $data));
		$this->setClientId($this->get('client_id', $data));
		$this->setClientSecret($this->get('client_secret', $data));

		return $this;
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		$arr = [
			'id'            => $this->getId(),
			'name'          => $this->getName(),
			'website'       => $this->getWebsite(),
			'redirect_uri'  => $this->getRedirectUris(),
			'scopes'        => implode(' ', $this->getScopes()),
			'client_id'     => $this->getClientId(),
			'client_secret' => $this->getClientSecret()
		];

		return array_filter($arr);
	}

}

