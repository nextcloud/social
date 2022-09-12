<?php
declare(strict_types=1);

// Nextcloud Social
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="social_application")
 */
class Application {
	/**
	 * @ORM\Id
	 * @ORM\Column
	 * @ORM\GeneratedValue
	 */
	private int $id = 0;

	/**
	 * @ORM\Column(name="app_name")
	 */
	private string $appName = '';

	/**
	 * @ORM\Column(name="app_website")
	 */
	private string $appWebsite = '';

	/**
	 * @ORM\Column(name="app_redirect_uris")
	 */
	private array $appRedirectUris = [];

	/**
	 * @ORM\Column(name="app_client_id")
	 */
	private string $appClientId = '';

	/**
	 * @ORM\Column(name="app_client_secret")
	 */
	private string $appClientSecret = '';

	/**
	 * @ORM\Column(name="app_scopes")
	 */
	private array $appScopes = [];

	/**
	 * @ORM\Column(name="auth_scopes")
	 */
	private array $authScopes = [];

	/**
	 * @ORM\Column(name="auth_account")
	 */
	private string $authAccount = '';

	/**
	 * @ORM\Column(name="auth_user_id")
	 */
	private string $authUserId = '';

	/**
	 * @ORM\Column(name="auth_code")
	 */
	private string $authCode = '';

	/**
	 * @ORM\Column(name="last_update")
	 */
	private int $lastUpdate = -1;

	/**
	 * @ORM\Column
	 */
	private string $token = '';

	/**
	 * @ORM\Column
	 */
	private DateTimeInterface $creation;

	public function __construct() {
		$this->lastUpdate = (new \DateTime('now'))->getTimestamp();
		$this->creation = new \DateTime('now');
	}

	/**
	 * @return list<string>
	 */
	static public function getScopesFromString(string $scopes): array {
		return explode(' ', $scopes);
	}

	public function getId(): int {
		return $this->id;
	}

	public function getAppName(): string {
		return $this->appName;
	}

	public function setAppName(string $appName): void {
		$this->appName = $appName;
	}

	public function getAppWebsite(): string {
		return $this->appWebsite;
	}

	public function setAppWebsite(string $appWebsite): void {
		$this->appWebsite = $appWebsite;
	}

	public function getAppRedirectUris(): array {
		return $this->appRedirectUris;
	}

	public function setAppRedirectUris(array $appRedirectUris): void {
		$this->appRedirectUris = $appRedirectUris;
	}

	public function getAppClientId(): string {
		return $this->appClientId;
	}

	public function setAppClientId(string $appClientId): void {
		$this->appClientId = $appClientId;
	}

	public function getAppClientSecret(): string {
		return $this->appClientSecret;
	}

	public function setAppClientSecret(string $appClientSecret): void {
		$this->appClientSecret = $appClientSecret;
	}

	public function getAppScopes(): array {
		return $this->appScopes;
	}

	public function setAppScopes(array $appScopes): void {
		$this->appScopes = $appScopes;
	}

	public function getAuthScopes(): array {
		return $this->authScopes;
	}

	public function setAuthScopes(array $authScopes): void {
		$this->authScopes = $authScopes;
	}

	public function getAuthAccount(): string {
		return $this->authAccount;
	}

	public function setAuthAccount(string $authAccount): void {
		$this->authAccount = $authAccount;
	}

	public function getAuthUserId(): string {
		return $this->authUserId;
	}

	public function setAuthUserId(string $authUserId): void {
		$this->authUserId = $authUserId;
	}

	public function getAuthCode(): string {
		return $this->authCode;
	}

	public function setAuthCode(string $authCode): void {
		$this->authCode = $authCode;
	}

	public function getLastUpdate(): int {
		return $this->lastUpdate;
	}

	public function setLastUpdate(int $lastUpdate): void {
		$this->lastUpdate = $lastUpdate;
	}

	public function getToken(): string {
		return $this->token;
	}

	public function setToken(string $token): void {
		$this->token = $token;
	}

	public function getCreation(): DateTimeInterface {
		return $this->creation;
	}

	public function setCreation(DateTimeInterface $creation): void {
		$this->creation = $creation;
	}
}
