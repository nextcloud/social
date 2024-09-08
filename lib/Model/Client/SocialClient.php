<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use DateTime;
use Exception;
use JsonSerializable;
use OCA\Social\Tools\IQueryRow;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class ClientApp
 *
 * @package OCA\Social\Model\Client
 */
class SocialClient implements IQueryRow, JsonSerializable {
	use TArrayTools;

	private int $id = 0;
	private string $appName = '';
	private string $appWebsite = '';
	private array $appRedirectUris = [];
	private string $appClientId = '';
	private string $appClientSecret = '';
	private array $appScopes = [];
	private array $authScopes = [];
	private string $authAccount = '';
	private string $authUserId = '';
	private string $authCode = '';
	private int $lastUpdate = -1;
	private string $token = '';
	private int $creation = -1;

	//	/** @var array */
	//	private $tokenScopes = [];


	/**
	 * SocialClient constructor.
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
	 * @return SocialClient
	 */
	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getAppName(): string {
		return $this->appName;
	}

	/**
	 * @param string $appName
	 *
	 * @return SocialClient
	 */
	public function setAppName(string $appName): self {
		$this->appName = $appName;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getAppWebsite(): string {
		return $this->appWebsite;
	}

	/**
	 * @param string $appWebsite
	 *
	 * @return SocialClient
	 */
	public function setAppWebsite(string $appWebsite): self {
		$this->appWebsite = $appWebsite;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getAppRedirectUris(): array {
		return $this->appRedirectUris;
	}

	/**
	 * @param array $appRedirectUris
	 *
	 * @return SocialClient
	 */
	public function setAppRedirectUris(array $appRedirectUris): self {
		$this->appRedirectUris = $appRedirectUris;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getAppClientId(): string {
		return $this->appClientId;
	}

	/**
	 * @param string $appClientId
	 *
	 * @return SocialClient
	 */
	public function setAppClientId(string $appClientId): self {
		$this->appClientId = $appClientId;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getAppClientSecret(): string {
		return $this->appClientSecret;
	}

	/**
	 * @param string $appClientSecret
	 *
	 * @return SocialClient
	 */
	public function setAppClientSecret(string $appClientSecret): self {
		$this->appClientSecret = $appClientSecret;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getAppScopes(): array {
		return $this->appScopes;
	}

	/**
	 * @param array $appScopes
	 *
	 * @return SocialClient
	 */
	public function setAppScopes(array $appScopes): self {
		$this->appScopes = $appScopes;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getAuthScopes(): array {
		return $this->authScopes;
	}

	/**
	 * @param array $scopes
	 *
	 * @return SocialClient
	 */
	public function setAuthScopes(array $scopes): self {
		$this->authScopes = $scopes;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getAuthAccount(): string {
		return $this->authAccount;
	}

	/**
	 * @param string $authAccount
	 *
	 * @return SocialClient
	 */
	public function setAuthAccount(string $authAccount): self {
		$this->authAccount = $authAccount;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getAuthUserId(): string {
		return $this->authUserId;
	}

	/**
	 * @param string $authUserId
	 *
	 * @return SocialClient
	 */
	public function setAuthUserId(string $authUserId): self {
		$this->authUserId = $authUserId;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getAuthCode(): string {
		return $this->authCode;
	}

	/**
	 * @param string $authCode
	 *
	 * @return SocialClient
	 */
	public function setAuthCode(string $authCode): self {
		$this->authCode = $authCode;

		return $this;
	}

	//
	//	/**
	//	 * @return string
	//	 */
	//	public function getAuthRedirectUri(): string {
	//		return $this->authRedirectUri;
	//	}
	//
	//	/**
	//	 * @param string $authRedirectUri
	//	 *
	//	 * @return SocialClient
	//	 */
	//	public function setAuthRedirectUri(string $authRedirectUri): self {
	//		$this->authRedirectUri = $authRedirectUri;
	//
	//		return $this;
	//	}


	/**
	 * @return int
	 */
	public function getLastUpdate(): int {
		return $this->lastUpdate;
	}

	/**
	 * @param int $lastUpdate
	 *
	 * @return SocialClient
	 */
	public function setLastUpdate(int $lastUpdate): self {
		$this->lastUpdate = $lastUpdate;

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
	 * @return SocialClient
	 */
	public function setToken(string $token): self {
		$this->token = $token;

		return $this;
	}


	//	/**
	//	 * @return array
	//	 */
	//	public function getTokenScopes(): array {
	//		return $this->tokenScopes;
	//	}
	//
	//	/**
	//	 * @param array $scopes
	//	 *
	//	 * @return SocialClient
	//	 */
	//	public function setTokenScopes(array $scopes): self {
	//		$this->tokenScopes = $scopes;
	//
	//		return $this;
	//	}


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
	 * @param string $scopes
	 *
	 * @return array
	 */
	public function getScopesFromString(string $scopes): array {
		return explode(' ', $scopes);
	}


	/**
	 * @param array $data
	 *
	 * @return SocialClient
	 * @throws Exception
	 */
	public function importFromDatabase(array $data): self {
		$this->setId($this->getInt('id', $data));
		$this->setAppName($this->get('app_name', $data));
		$this->setAppWebsite($this->get('app_website', $data));
		$this->setAppRedirectUris($this->getArray('app_redirect_uris', $data));
		$this->setAppClientId($this->get('app_client_id', $data));
		$this->setAppClientSecret($this->get('app_client_secret', $data));
		$this->setAppScopes($this->getArray('app_scopes', $data));
		$this->setAuthScopes($this->getArray('auth_scopes', $data));
		$this->setAuthAccount($this->get('auth_account', $data));
		$this->setAuthUserId($this->get('auth_user_id', $data));
		$this->setAuthCode($this->get('auth_code', $data));
		$this->setToken($this->get('token', $data));

		$date = new DateTime($this->get('last_update', $data, ''));
		$this->setLastUpdate($date->getTimestamp());
		$this->setCreation($this->getInt('creation', $data));

		return $this;
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'app_name' => $this->getAppName(),
			'app_website' => $this->getAppWebsite(),
			'app_scopes' => $this->getAppScopes(),
			'app_client_id' => $this->getAppClientId(),
			'app_client_secret' => $this->getAppClientSecret(),
			'app_redirect_uris' => $this->getAppRedirectUris(),
			'auth_scopes' => $this->getAuthScopes(),
			'auth_account' => $this->getAuthAccount(),
			'auth_user_id' => $this->getAuthUserId(),
			'auth_code' => $this->getAuthCode(),
			'token' => $this->getToken(),
			'last_update' => $this->getLastUpdate(),
			'creation' => $this->getCreation()
		];
	}
}
