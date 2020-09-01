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


use daita\MySmallPhpTools\IQueryRow;
use daita\MySmallPhpTools\Traits\TArrayTools;
use JsonSerializable;
use OCA\Social\Model\ActivityPub\Actor\Person;


/**
 * Class Instance
 *
 * @package OCA\Social\Model
 */
class Instance implements IQueryRow, JsonSerializable {


	use TArrayTools;


	/** @var bool */
	private $local = false;

	/** @var string */
	private $uri = '';

	/** @var string */
	private $title = '';

	/** @var string */
	private $version = '';

	/** @var string */
	private $shortDescription = '';

	/** @var string */
	private $description = '';

	/** @var string */
	private $email = '';

	/** @var array */
	private $urls = [];

	/** @var array */
	private $stats = [];

	/** @var array */
	private $usage = [];

	/** @var string */
	private $image = '';

	/** @var array */
	private $languages = [];

	/** @var bool */
	private $registrations = false;

	/** @var bool */
	private $approvalRequired = false;

	/** @var bool */
	private $invitesEnabled = false;

	/** @var Person */
	private $contactAccount;

	/** @var string */
	private $accountPrim;


	/**
	 * Instance constructor.
	 */
	public function __construct() {
	}


	/**
	 * @return bool
	 */
	public function isLocal(): bool {
		return $this->local;
	}

	/**
	 * @param bool $local
	 *
	 * @return Instance
	 */
	public function setLocal(bool $local): self {
		$this->local = $local;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getUri(): string {
		return $this->uri;
	}

	/**
	 * @param string $uri
	 *
	 * @return Instance
	 */
	public function setUri(string $uri): self {
		$this->uri = $uri;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getTitle(): string {
		return $this->title;
	}

	/**
	 * @param string $title
	 *
	 * @return Instance
	 */
	public function setTitle(string $title): self {
		$this->title = $title;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getVersion(): string {
		return $this->version;
	}

	/**
	 * @param string $version
	 *
	 * @return Instance
	 */
	public function setVersion(string $version): self {
		$this->version = $version;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getShortDescription(): string {
		return $this->shortDescription;
	}

	/**
	 * @param string $shortDescription
	 *
	 * @return Instance
	 */
	public function setShortDescription(string $shortDescription): self {
		$this->shortDescription = $shortDescription;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getDescription(): string {
		return $this->description;
	}

	/**
	 * @param string $description
	 *
	 * @return Instance
	 */
	public function setDescription(string $description): self {
		$this->description = $description;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getEmail(): string {
		return $this->email;
	}

	/**
	 * @param string $email
	 *
	 * @return Instance
	 */
	public function setEmail(string $email): self {
		$this->email = $email;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getUrls(): array {
		return $this->urls;
	}

	/**
	 * @param array $urls
	 *
	 * @return Instance
	 */
	public function setUrls(array $urls): self {
		$this->urls = $urls;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getStats(): array {
		return $this->stats;
	}

	/**
	 * @param array $stats
	 *
	 * @return Instance
	 */
	public function setStats(array $stats): self {
		$this->stats = $stats;

		return $this;
	}


	/**
	 * @return array
	 */
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

	/**
	 * @param string $image
	 *
	 * @return Instance
	 */
	public function setImage(string $image): self {
		$this->image = $image;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getLanguages(): array {
		return $this->languages;
	}

	/**
	 * @param array $languages
	 *
	 * @return Instance
	 */
	public function setLanguages(array $languages): self {
		$this->languages = $languages;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isRegistrations(): bool {
		return $this->registrations;
	}

	/**
	 * @param bool $registrations
	 *
	 * @return Instance
	 */
	public function setRegistrations(bool $registrations): self {
		$this->registrations = $registrations;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isApprovalRequired(): bool {
		return $this->approvalRequired;
	}

	/**
	 * @param bool $approvalRequired
	 *
	 * @return Instance
	 */
	public function setApprovalRequired(bool $approvalRequired): self {
		$this->approvalRequired = $approvalRequired;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isInvitesEnabled(): bool {
		return $this->invitesEnabled;
	}

	/**
	 * @param bool $invitesEnabled
	 *
	 * @return Instance
	 */
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

	/**
	 * @return Person
	 */
	public function getContactAccount(): Person {
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

	/**
	 * @return string
	 */
	public function getAccountPrim(): string {
		return $this->accountPrim;
	}

	/**
	 * @param string $prim
	 *
	 * @return Instance
	 */
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
			'uri'               => $this->getUri(),
			'title'             => $this->getTitle(),
			'version'           => $this->getVersion(),
			'short_description' => $this->getShortDescription(),
			'description'       => $this->getDescription(),
			'email'             => $this->getEmail(),
			'urls'              => $this->getUrls(),
			'stats'             => $this->getStats(),
			'thumbnail'         => $this->getImage(),
			'languages'         => $this->getLanguages(),
			'registrations'     => $this->isRegistrations(),
			'approval_required' => $this->isApprovalRequired(),
			'invites_enabled'   => $this->isInvitesEnabled()
		];

		if ($this->hasContactAccount()) {
			$arr['contact_account'] = $this->getContactAccount();
		}

		return $arr;
	}

}

