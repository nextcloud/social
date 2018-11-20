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


namespace OCA\Social\Model\ActivityPub;


use JsonSerializable;


/**
 * Class Actor
 *
 * @package OCA\Social\Model\ActivityPub
 */
class Person extends ACore implements JsonSerializable {


	const TYPE = 'Person';


	/** @var string */
	private $userId = '';

	/** @var string */
	private $name = '';

	/** @var string */
	private $preferredUsername = '';

	/** @var string */
	private $publicKey = '';

	/** @var string */
	private $privateKey = '';

	/** @var int */
	private $creation = 0;

	/** @var string */
	private $account = '';

	/** @var string */
	private $following = '';

	/** @var string */
	private $followers = '';

	/** @var string */
	private $inbox = '';

	/** @var string */
	private $outbox = '';

	/** @var string */
	private $sharedInbox = '';

	/** @var string */
	private $featured = '';

	/**
	 * Person constructor.
	 *
	 * @param ACore $parent
	 */
	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
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
	 * @return Person
	 */
	public function setUserId(string $userId): Person {
		$this->userId = $userId;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getPreferredUsername(): string {
		return $this->preferredUsername;
	}

	/**
	 * @param string $preferredUsername
	 *
	 * @return Person
	 */
	public function setPreferredUsername(string $preferredUsername): Person {
		$this->preferredUsername = $preferredUsername;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getPublicKey(): string {
		return $this->publicKey;
	}

	/**
	 * @param string $publicKey
	 *
	 * @return Person
	 */
	public function setPublicKey(string $publicKey): Person {
		$this->publicKey = $publicKey;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getPrivateKey(): string {
		return $this->privateKey;
	}

	/**
	 * @param string $privateKey
	 *
	 * @return Person
	 */
	public function setPrivateKey(string $privateKey): Person {
		$this->privateKey = $privateKey;

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
	 * @return Person
	 */
	public function setCreation(int $creation): Person {
		$this->creation = $creation;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getFollowing(): string {
		return $this->following;
	}

	/**
	 * @param string $following
	 *
	 * @return Person
	 */
	public function setFollowing(string $following): Person {
		$this->following = $following;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getFollowers(): string {
		return $this->followers;
	}

	/**
	 * @param string $followers
	 *
	 * @return Person
	 */
	public function setFollowers(string $followers): Person {
		$this->followers = $followers;

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
	 * @return Person
	 */
	public function setAccount(string $account): Person {
		$this->account = $account;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getInbox(): string {
		return $this->inbox;
	}

	/**
	 * @param string $inbox
	 *
	 * @return Person
	 */
	public function setInbox(string $inbox): Person {
		$this->inbox = $inbox;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getOutbox(): string {
		return $this->outbox;
	}

	/**
	 * @param string $outbox
	 *
	 * @return Person
	 */
	public function setOutbox(string $outbox): Person {
		$this->outbox = $outbox;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getSharedInbox(): string {
		return $this->sharedInbox;
	}

	/**
	 * @param string $sharedInbox
	 *
	 * @return Person
	 */
	public function setSharedInbox(string $sharedInbox): Person {
		$this->sharedInbox = $sharedInbox;

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
	 * @return Person
	 */
	public function setName(string $name): Person {
		$this->name = $name;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getFeatured(): string {
		return $this->featured;
	}

	/**
	 * @param string $featured
	 *
	 * @return Person
	 */
	public function setFeatured(string $featured): Person {
		$this->featured = $featured;

		return $this;
	}


	/**
	 * @param array $data
	 */
	public function import(array $data) {
		parent::import($data);
		$this->setPreferredUsername($this->get('preferred_username', $data, ''))
			 ->setName($this->get('name', $data, ''))
			 ->setAccount($this->get('account', $data, ''))
			 ->setPublicKey($this->get('public_key', $data, ''))
			 ->setPrivateKey($this->get('private_key', $data, ''))
			 ->setInbox($this->get('inbox', $data, ''))
			 ->setOutbox($this->get('outbox', $data, ''))
			 ->setFollowers($this->get('followers', $data, ''))
			 ->setFollowing($this->get('following', $data, ''))
			 ->setSharedInbox($this->get('shared_inbox', $data, ''))
			 ->setFeatured($this->get('featured', $data, ''))
			 ->setCreation($this->getInt('creation', $data, 0));

//		if ($this->getPreferredUsername() === '') {
//			$this->setType('Invalid');
//		}
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return array_merge(
			parent::jsonSerialize(),
			[
				'aliases'           => [
					$this->getUrlRoot() . '@' . $this->getPreferredUsername(),
					$this->getUrlRoot() . 'users/' . $this->getPreferredUsername()
				],
				'preferredUsername' => $this->getPreferredUsername(),
				'name'              => $this->getName(),
				'inbox'             => $this->getInbox(),
				'outbox'            => $this->getOutbox(),
				'account'           => $this->getAccount(),
				'following'         => $this->getFollowing(),
				'followers'         => $this->getFollowers(),
				'endpoints'         =>
					['sharedInbox' => $this->getSharedInbox()],
				'publicKey'         => [
					'id'           => $this->getId() . '#main-key',
					'owner'        => $this->getId(),
					'publicKeyPem' => $this->getPublicKey()
				]
			]
		);
	}


}

