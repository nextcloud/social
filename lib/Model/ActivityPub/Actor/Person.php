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


namespace OCA\Social\Model\ActivityPub\Actor;


use DateTime;
use Exception;
use JsonSerializable;
use OCA\Social\AP;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Traits\TDetails;


/**
 * Class Actor
 *
 * @package OCA\Social\Model\ActivityPub
 */
class Person extends ACore implements JsonSerializable {


	use TDetails;


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

	/** @var int */
	private $avatarVersion = -1;


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
		if ($account !== '' && substr($account, 0, 1) === '@') {
			$account = substr($account, 1);
		}

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
	 * @return int
	 */
	public function getAvatarVersion(): int {
		return $this->avatarVersion;
	}

	/**
	 * @param int $avatarVersion
	 *
	 * @return Person
	 */
	public function setAvatarVersion(int $avatarVersion): Person {
		$this->avatarVersion = $avatarVersion;

		return $this;
	}


	/**
	 * @param array $data
	 *
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws InvalidOriginException
	 */
	public function import(array $data) {
		parent::import($data);
		$this->setPreferredUsername(
			$this->validate(ACore::AS_USERNAME, 'preferredUsername', $data, '')
		)
			 ->setPublicKey($this->get('publicKey.publicKeyPem', $data))
			 ->setSharedInbox($this->validate(ACore::AS_URL, 'endpoints.sharedInbox', $data))
			 ->setName($this->validate(ACore::AS_USERNAME, 'name', $data, ''))
			 ->setAccount($this->validate(ACore::AS_ACCOUNT, 'account', $data, ''))
			 ->setInbox($this->validate(ACore::AS_URL, 'inbox', $data, ''))
			 ->setOutbox($this->validate(ACore::AS_URL, 'outbox', $data, ''))
			 ->setFollowers($this->validate(ACore::AS_URL, 'followers', $data, ''))
			 ->setFollowing($this->validate(ACore::AS_URL, 'following', $data, ''))
			 ->setFeatured($this->validate(ACore::AS_URL, 'featured', $data, ''));

		/** @var Image $icon */
		$icon = AP::$activityPub->getItemFromType(Image::TYPE);
		$icon->import($this->getArray('icon', $data, []));

		if ($icon->getType() === Image::TYPE) {
			$this->setIcon($icon);
		}

	}


	/**
	 * @param array $data
	 */
	public function importFromDatabase(array $data) {
		parent::importFromDatabase($data);

		$this->setPreferredUsername(
			$this->validate(self::AS_USERNAME, 'preferred_username', $data, '')
		)
			 ->setUserId($this->get('user_id', $data, ''))
			 ->setName($this->validate(self::AS_USERNAME, 'name', $data, ''))
			 ->setAccount($this->validate(self::AS_ACCOUNT, 'account', $data, ''))
			 ->setPublicKey($this->get('public_key', $data, ''))
			 ->setPrivateKey($this->get('private_key', $data, ''))
			 ->setInbox($this->validate(self::AS_URL, 'inbox', $data, ''))
			 ->setOutbox($this->validate(self::AS_URL, 'outbox', $data, ''))
			 ->setFollowers($this->validate(self::AS_URL, 'followers', $data, ''))
			 ->setFollowing($this->validate(self::AS_URL, 'following', $data, ''))
			 ->setSharedInbox($this->validate(self::AS_URL, 'shared_inbox', $data, ''))
			 ->setFeatured($this->validate(self::AS_URL, 'featured', $data, ''))
			 ->setDetailsAll($this->getArray('details', $data, []));

		try {
			$dTime = new DateTime($this->get('creation', $data, 'yesterday'));
			$this->setCreation($dTime->getTimestamp());
		} catch (Exception $e) {
		}
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		$result = array_merge(
			parent::jsonSerialize(),
			[
				'aliases'           => [
					$this->getUrlSocial() . '@' . $this->getPreferredUsername(),
					$this->getUrlSocial() . 'users/' . $this->getPreferredUsername()
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

		if ($this->isCompleteDetails()) {
			$result['details'] = $this->getDetailsAll();
		}

		return $result;
	}

}
