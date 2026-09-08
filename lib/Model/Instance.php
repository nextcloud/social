<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Tools\IQueryRow;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class Instance
 *
 * @package OCA\Social\Model
 */
class Instance implements IQueryRow, JsonSerializable {
	use TArrayTools;

	private bool $local = false;
	private string $uri = '';
	private string $title = '';
	private string $version = '';
	private string $shortDescription = '';
	private string $description = '';
	private string $email = '';
	private array $urls = [];
	private array $stats = [];
	private array $usage = [];
	private string $image = '';
	private array $languages = [];
	private bool $registrations = false;
	private bool $approvalRequired = false;
	private bool $invitesEnabled = false;
	private ?Person $contactAccount = null;
	private ?string $accountPrim = null;

	public function isLocal(): bool {
		return $this->local;
	}

	public function setLocal(bool $local): self {
		$this->local = $local;

		return $this;
	}

	public function getUri(): string {
		return $this->uri;
	}

	public function setUri(string $uri): self {
		$this->uri = $uri;

		return $this;
	}

	public function getTitle(): string {
		return $this->title;
	}

	public function setTitle(string $title): self {
		$this->title = $title;

		return $this;
	}

	public function getVersion(): string {
		return '4.1.0 (compatible; Nextcloud Social ' . $this->version . ')';
	}

	public function setVersion(string $version): self {
		$this->version = $version;

		return $this;
	}

	public function getShortDescription(): string {
		return $this->shortDescription;
	}

	public function setShortDescription(string $shortDescription): self {
		$this->shortDescription = $shortDescription;

		return $this;
	}

	public function getDescription(): string {
		return $this->description;
	}

	public function setDescription(string $description): self {
		$this->description = $description;

		return $this;
	}

	public function getEmail(): string {
		return $this->email;
	}

	public function setEmail(string $email): self {
		$this->email = $email;

		return $this;
	}

	public function getUrls(): array {
		return $this->urls;
	}

	public function setUrls(array $urls): self {
		$this->urls = $urls;

		return $this;
	}

	public function getStats(): array {
		return $this->stats;
	}

	public function setStats(array $stats): self {
		$this->stats = $stats;

		return $this;
	}

	public function getUsage(): array {
		return $this->usage;
	}

	/**
	 * @param array $usage
	 *
	 * @return Instance
	 */
	public function setUsage(array $usage): self {
		$this->usage = $usage;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getImage(): string {
		return $this->image;
	}

	public function setImage(string $image): self {
		$this->image = $image;

		return $this;
	}

	public function getLanguages(): array {
		return $this->languages;
	}

	public function setLanguages(array $languages): self {
		$this->languages = $languages;

		return $this;
	}

	public function isRegistrations(): bool {
		return $this->registrations;
	}

	public function setRegistrations(bool $registrations): self {
		$this->registrations = $registrations;

		return $this;
	}

	public function isApprovalRequired(): bool {
		return $this->approvalRequired;
	}

	public function setApprovalRequired(bool $approvalRequired): self {
		$this->approvalRequired = $approvalRequired;

		return $this;
	}

	public function isInvitesEnabled(): bool {
		return $this->invitesEnabled;
	}

	public function setInvitesEnabled(bool $invitesEnabled): self {
		$this->invitesEnabled = $invitesEnabled;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function hasContactAccount(): bool {
		return ($this->contactAccount !== null);
	}

	public function getContactAccount(): ?Person {
		return $this->contactAccount;
	}

	/**
	 * @param Person $account
	 *
	 * @return Instance
	 */
	public function setContactAccount(Person $account): self {
		$this->contactAccount = $account;

		return $this;
	}

	public function getAccountPrim(): ?string {
		return $this->accountPrim;
	}

	public function setAccountPrim(string $prim): self {
		$this->accountPrim = $prim;

		return $this;
	}


	/**
	 * @param array $data
	 *
	 * @return $this
	 */
	public function importFromDatabase(array $data): self {
		$this->setLocal($this->getBool('local', $data));
		$this->setUri($this->get('uri', $data));
		$this->setTitle($this->get('title', $data));
		$this->setVersion($this->get('version', $data));
		$this->setShortDescription($this->get('short_description', $data));
		$this->setDescription($this->get('description', $data));
		$this->setEmail($this->get('email', $data));
		$this->setUrls($this->getArray('urls', $data));
		$this->setStats($this->getArray('stats', $data));
		$this->setUsage($this->getArray('usage', $data));
		$this->setImage($this->get('image', $data));
		$this->setLanguages($this->getArray('languages', $data));
		$this->setAccountPrim($this->get('account_prim', $data));

		//		$contact = new Person();
		//		$this->setContactAccount($contact);

		return $this;
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		$arr = [
			'uri' => $this->getUri(),
			'title' => $this->getTitle(),
			'version' => $this->getVersion(),
			'short_description' => $this->getShortDescription(),
			'description' => $this->getDescription(),
			'email' => $this->getEmail(),
			'urls' => $this->getUrls(),
			'stats' => $this->getStats(),
			'thumbnail' => $this->getImage(),
			'languages' => $this->getLanguages(),
			'registrations' => $this->isRegistrations(),
			'approval_required' => $this->isApprovalRequired(),
			'invites_enabled' => $this->isInvitesEnabled()
		];

		if ($this->hasContactAccount()) {
			$arr['contact_account'] = $this->getContactAccount();
		}

		return $arr;
	}
}
