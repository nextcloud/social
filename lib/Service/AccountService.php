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

namespace OCA\Social\Service;

use Exception;
use OC\User\NoUserException;
use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AccountAlreadyExistsException;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\InstancePath;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\Accounts\IAccountManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Class ActorService
 *
 * @package OCA\Social\Service
 */
class AccountService {
	public const KEY_PAIR_LIFESPAN = 7;
	public const TIME_RETENTION = 3600; // seconds before fully delete account
	use TArrayTools;

	private IUserManager $userManager;
	private IUserSession $userSession;
	private IAccountManager $accountManager;
	private ActorsRequest $actorsRequest;
	private FollowsRequest $followsRequest;
	private StreamRequest $streamRequest;
	private ActorService $actorService;
	private ActivityService $activityService;
	private AccountService $accountService;
	private SignatureService $signatureService;
	private DocumentService $documentService;
	private ConfigService $configService;
	private LoggerInterface $logger;

	public function __construct(
		IUserManager $userManager,
		IUserSession $userSession,
		IAccountManager $accountManager,
		ActorsRequest $actorsRequest,
		FollowsRequest $followsRequest,
		StreamRequest $streamRequest,
		ActorService $actorService,
		ActivityService $activityService,
		DocumentService $documentService,
		SignatureService $signatureService,
		ConfigService $configService,
		LoggerInterface $logger
	) {
		$this->userManager = $userManager;
		$this->userSession = $userSession;
		$this->accountManager = $accountManager;
		$this->actorsRequest = $actorsRequest;
		$this->followsRequest = $followsRequest;
		$this->streamRequest = $streamRequest;
		$this->actorService = $actorService;
		$this->activityService = $activityService;
		$this->documentService = $documentService;
		$this->signatureService = $signatureService;
		$this->configService = $configService;
		$this->logger = $logger;
	}


	/**
	 * @param string $username
	 *
	 * @return Person
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 */
	public function getActor(string $username): Person {
		$actor = $this->actorsRequest->getFromUsername($username);

		return $actor;
	}

	/**
	 * @param string $id
	 *
	 * @return Person
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 */
	public function getFromId(string $id): Person {
		$actor = $this->actorsRequest->getFromId($id);

		return $actor;
	}


	/**
	 * @return Person
	 * @throws AccountDoesNotExistException
	 */
	public function getCurrentViewer(): Person {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new AccountDoesNotExistException();
		}

		try {
			return $this->getActorFromUserId($user->getUID());
		} catch (Exception $e) {
			throw new AccountDoesNotExistException();
		}
	}


	/**
	 * @param string $userId
	 * @param bool $create
	 *
	 * @return Person
	 * @throws AccountAlreadyExistsException
	 * @throws ActorDoesNotExistException
	 * @throws NoUserException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function getActorFromUserId(string $userId, bool $create = false): Person {
		$this->confirmUserId($userId);
		try {
			$actor = $this->actorsRequest->getFromUserId($userId);
		} catch (ActorDoesNotExistException $e) {
			if ($create) {
				$this->createActor($userId, $userId);
				$actor = $this->actorsRequest->getFromUserId($userId);
			} else {
				throw new ActorDoesNotExistException();
			}
		}

		return $actor;
	}


	/**
	 * Method should be called by the frontend and will generate a fresh Social account for
	 * the user, using the userId and the username.
	 *
	 * Pair of keys are created at this point.
	 *
	 * Return exceptions if an account already exist for this user or if the username is already
	 * taken
	 *
	 * @param string $userId
	 * @param string $username
	 *
	 * @throws AccountAlreadyExistsException
	 * @throws ItemAlreadyExistsException
	 * @throws NoUserException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 */
	public function createActor(string $userId, string $username) {
		$this->confirmUserId($userId);
		$this->checkActorUsername($username);

		try {
			$actor = $this->actorsRequest->getFromUsername($username);
			if ($actor->getDeleted() > 0) {
				throw new AccountAlreadyExistsException(
					'actor with that name was deleted but is still in retention. Please try again later'
				);
			}
			throw new AccountAlreadyExistsException('actor with that name already exist');
		} catch (ActorDoesNotExistException $e) {
			/* we do nohtin */
		}

		try {
			$this->actorsRequest->getFromUserId($userId);
			throw new AccountAlreadyExistsException('account for this user already exist');
		} catch (ActorDoesNotExistException $e) {
			/* we do nohtin */
		}

		$actor = new Person();
		$actor->setUserId($userId);
		$actor->setPreferredUsername($username);

		$this->signatureService->generateKeys($actor);
		$this->actorsRequest->create($actor);

		// generate cache.
		$this->cacheLocalActorByUsername($username);

		// generate loopback
		$this->followsRequest->generateLoopbackAccount($actor);
	}


	/**
	 * @param string $handle
	 *
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 */
	public function deleteActor(string $handle): void {
		try {
			$actor = $this->actorsRequest->getFromUsername($handle);
		} catch (ActorDoesNotExistException $e) {
			return;
		}

		// set as deleted locally
		$this->actorsRequest->setAsDeleted($actor->getPreferredUsername());

		// delete related data
		/** @var PersonInterface $interface */
		$interface = AP::$activityPub->getInterfaceFromType(Person::TYPE);
		$interface->delete($actor);

		// broadcast delete event
		$delete = new Delete();
		$delete->setId($actor->getId() . '#delete');
		$delete->setActorId($actor->getId());
		$delete->setToArray([ACore::CONTEXT_PUBLIC]);
		$delete->setObjectId($actor->getId());
		$delete->addInstancePath(
			new InstancePath(
				$actor->getInbox(),
				InstancePath::TYPE_ALL,
				InstancePath::PRIORITY_LOW
			)
		);
		$this->signatureService->signObject($actor, $delete);

		$this->activityService->request($delete);
	}


	/**
	 * @param string $username
	 *
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function cacheLocalActorByUsername(string $username) {
		try {
			$actor = $this->getActor($username);

			try {
				$this->updateCacheLocalActorName($actor);
			} catch (NoUserException $e) {
				return;
			}

			try {
				$iconId = $this->documentService->cacheLocalAvatarByUsername($actor);
				$actor->setIconId($iconId);
			} catch (ItemUnknownException | ItemAlreadyExistsException $e) {
			}

			$this->addLocalActorDetailCount($actor);
			$this->actorService->cacheLocalActor($actor);
		} catch (ActorDoesNotExistException $e) {
		}
	}


	/**
	 * @param Person $actor
	 */
	public function cacheLocalActorDetailCount(Person $actor) {
		if (!$actor->isLocal()) {
			return;
		}

		$this->addLocalActorDetailCount($actor);
		$this->actorService->cacheLocalActor($actor);
	}


	/**
	 * @param Person $actor
	 */
	public function addLocalActorDetailCount(Person $actor) {
		$lastPostCreation = '';
		try {
			$lastPost = $this->streamRequest->lastNoteFromActorId($actor->getId());
			$lastPostCreation = date('Y-m-d', $lastPost->getPublishedTime());
		} catch (StreamNotFoundException $e) {
		}

		$count = [
			'followers' => $this->followsRequest->countFollowers($actor->getId()),
			'following' => $this->followsRequest->countFollowing($actor->getId()),
			'post' => $this->streamRequest->countNotesFromActorId($actor->getId())
		];
		$actor->setDetailArray('count', $count);
		$actor->setDetail('last_post_creation', $lastPostCreation);
	}


	/**
	 * @param Person $actor
	 *
	 * @throws NoUserException
	 */
	private function updateCacheLocalActorName(Person &$actor) {
		$user = $this->userManager->get($actor->getUserId());
		if ($user === null) {
			throw new NoUserException();
		}

		try {
			$account = $this->accountManager->getAccount($user);
			$displayNameProperty = $account->getProperty(IAccountManager::PROPERTY_DISPLAYNAME);
			if ($displayNameProperty->getScope() === IAccountManager::SCOPE_PUBLISHED) {
				$actor->setName($displayNameProperty->getValue());
			}
		} catch (Exception $e) {
			$this->logger->error('Issue while trying to updateCacheLocalActorName: ' . $e->getMessage());
		}
	}


	/**
	 * @param string $username
	 */
	private function checkActorUsername(string $username) {
		$accepted = 'qwertyuiopasdfghjklzxcvbnm';

		return;
	}


	/**
	 * @return int
	 * @throws Exception
	 */
	public function manageDeletedActors(): int {
		$entries = $this->actorsRequest->getAll();
		$deleted = 0;
		foreach ($entries as $item) {
			// delete after an hour
			if ($item->getDeleted() === 0) {
				continue;
			}

			if ($item->getDeleted() < (time() - self::TIME_RETENTION)) {
				$this->actorsRequest->delete($item->getPreferredUsername());
				$deleted++;
			}
		}

		return $deleted;
	}


	/**
	 * @return int
	 * @throws Exception
	 */
	public function manageCacheLocalActors(): int {
		$update = $this->actorsRequest->getAll();
		foreach ($update as $item) {
			try {
				$this->cacheLocalActorByUsername($item->getPreferredUsername());
			} catch (Exception $e) {
			}
		}

		return sizeof($update);
	}


	/**
	 * @return int
	 * @throws Exception
	 */
	public function blindKeyRotation(): int {
		$update = $this->actorsRequest->getAll();
		$count = 0;
		foreach ($update as $actor) {
			try {
				if ($actor->getCreation() < (time() - (self::KEY_PAIR_LIFESPAN * 3600 * 24))) {
					$this->signatureService->generateKeys($actor);
					$this->actorsRequest->refreshKeys($actor);
					$count++;
				}
			} catch (Exception $e) {
			}
		}

		return $count;
	}


	/**
	 * @param string $userId
	 *
	 * @return IUser
	 * @throws NoUserException
	 */
	public function confirmUserId(string &$userId): IUser {
		$user = $this->userManager->get($userId);

		if ($user === null) {
			throw new NoUserException('user does not exist');
		}

		$userId = $user->getUID();

		return $user;
	}
}
