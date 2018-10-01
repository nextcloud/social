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
use OCA\Social\Service\ActivityPubService;

class Actor extends Core implements JsonSerializable {

	/** @var string */
	private $userId = '';

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


	/**
	 * Actor constructor.
	 *
	 * @param bool $isTopLevel
	 */
	public function __construct(bool $isTopLevel = false) {
		parent::__construct($isTopLevel);

		$this->setType('Person');
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
	 * @return Actor
	 */
	public function setUserId(string $userId): Actor {
		$this->userId = $userId;

		if ($this->getPreferredUsername() === '') {
			$this->setPreferredUsername($userId);
		}

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
	 * @return Actor
	 */
	public function setPreferredUsername(string $preferredUsername): Actor {
		if ($preferredUsername !== '') {
			$this->preferredUsername = $preferredUsername;
		}

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
	 * @return Actor
	 */
	public function setPublicKey(string $publicKey): Actor {
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
	 * @return Actor
	 */
	public function setPrivateKey(string $privateKey): Actor {
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
	 * @return Actor
	 */
	public function setCreation(int $creation): Actor {
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
	 * @return Actor
	 */
	public function setFollowing(string $following): Actor {
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
	 * @return Actor
	 */
	public function setFollowers(string $followers): Actor {
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
	 * @return Actor
	 */
	public function setAccount(string $account): Actor {
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
	 * @return Actor
	 */
	public function setInbox(string $inbox): Actor {
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
	 * @return Actor
	 */
	public function setOutbox(string $outbox): Actor {
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
	 * @return Actor
	 */
	public function setSharedInbox(string $sharedInbox): Actor {
		$this->sharedInbox = $sharedInbox;

		return $this;
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'@context' => [
				ActivityPubService::CONTEXT_ACTIVITYSTREAMS,
				ActivityPubService::CONTEXT_SECURITY
			],
			'aliases'  => [
				$this->getRoot() . '@' . $this->getPreferredUsername(),
				$this->getRoot() . 'users/' . $this->getPreferredUsername()
			],

			'id'                => $this->getId(),
			'type'              => $this->getType(),
			'preferredUsername' => $this->getPreferredUsername(),
			'inbox'             => $this->getInbox(),
			'outbox'            => $this->getOutbox(),
			'following'         => $this->getFollowing(),
			'followers'         => $this->getFollowers(),
			'url'               => $this->getId(),
			'endpoints'         =>
				['sharedInbox' => $this->getSharedInbox()],

			'publicKey' => [
				'id'           => $this->getId() . '#main-key',
				'owner'        => $this->getId(),
				'publicKeyPem' => $this->getPublicKey()
			]
		];
	}


}

