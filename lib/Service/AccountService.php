<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
use OCA\Social\Exceptions\InvalidHandleException;
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
	/** How long a soft-deleted actor is kept before `manageDeletedActors()` purges it. */
	public const TIME_RETENTION = 3600;

	/**
	 * What may appear in a `preferredUsername`. Letters, digits and underscore,
	 * with dot and dash allowed inside but not at either end — the intersection
	 * of what Mastodon, Pleroma and GoToSocial accept.
	 */
	private const HANDLE_PATTERN = '/^[a-zA-Z0-9_]+([a-zA-Z0-9_.-]*[a-zA-Z0-9_])?$/';
	private const HANDLE_MAX_LENGTH = 64;

	/**
	 * Age, in days, past which `blindKeyRotation()` would renew an actor's key pair.
	 * The rotation is not currently scheduled; the constant exists so the method does
	 * not fatal on an undefined constant if it is ever called.
	 */
	public const KEY_PAIR_LIFESPAN = 60;

	private ?string $userId = null;

	private IUserManager $userManager;
	private IUserSession $userSession;
	private IAccountManager $accountManager;
	private ActorsRequest $actorsRequest;
	private FollowsRequest $followsRequest;
	private StreamRequest $streamRequest;
	private ActorService $actorService;
	private ActivityService $activityService;
	private DocumentService $documentService;
	private SignatureService $signatureService;
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
		LoggerInterface $logger,
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
			throw new AccountDoesNotExistException('No user is currently logged in');
		}

		try {
			return $this->getActorFromUserId($user->getUID());
		} catch (Exception $e) {
			throw new AccountDoesNotExistException('Account not found for current user: ' . $e->getMessage());
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
				$this->createActor($userId, $this->generateHandleFromUserId($userId));
				$actor = $this->actorsRequest->getFromUserId($userId);
			} else {
				throw new ActorDoesNotExistException('Actor not found for user: ' . $userId);
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
	 * @throws InvalidHandleException
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
	 * Stores whether new follows towards this user's actor need manual approval,
	 * and refreshes the actor cache so the flag reaches the actor document and
	 * account entity. Remote servers pick the change up when they next refresh
	 * the actor.
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function setLocked(string $userId, bool $locked): void {
		$actor = $this->getActorFromUserId($userId);
		$actor->setLocked($locked);
		$this->actorsRequest->updateLocked($actor);
		$this->cacheLocalActorByUsername($actor->getPreferredUsername());
	}

	/**
	 * Stores the profile metadata fields (at most four name/value pairs) and
	 * refreshes the actor cache so they reach the actor document as
	 * PropertyValue attachments and the account entity as `fields`.
	 *
	 * @param array[] $fields [['name' => string, 'value' => string], …]
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function setFields(string $userId, array $fields): void {
		$actor = $this->getActorFromUserId($userId);
		$actor->setFields($fields);
		$this->actorsRequest->updateFields($actor);
		$this->cacheLocalActorByUsername($actor->getPreferredUsername());
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
			} catch (ItemUnknownException|ItemAlreadyExistsException $e) {
			}

			$this->loadLocalActorHeader($actor);

			$this->addLocalActorDetailCount($actor);
			$this->actorService->cacheLocalActor($actor);
		} catch (ActorDoesNotExistException $e) {
		}
	}

	/**
	 * Load the cached header document URL for a local actor.
	 *
	 * @param Person $actor
	 */
	private function loadLocalActorHeader(Person $actor): void {
		try {
			$headerUrl = $this->actorService->getCachedHeader($actor);
			if ($headerUrl !== '') {
				$actor->setHeader($headerUrl);
			}
		} catch (Exception $e) {
		}
	}

	/**
	 * @param string $username
	 * @param string $description
	 *
	 * @return Person
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws NoUserException
	 * @throws ItemAlreadyExistsException
	 * @throws UrlCloudException
	 */
	/**
	 * @param Person $actor
	 */
	public function cacheLocalActorDetailCount(Person $actor) {
		if (!$actor->isLocal()) {
			return;
		}

		$this->addLocalActorDetailCount($actor);
		$this->actorService->cacheLocalActorDetails($actor);
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
			'follow_requests' => $this->followsRequest->countPendingRequests($actor->getId()),
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
	private function updateCacheLocalActorName(Person $actor) {
		$user = $this->userManager->get($actor->getUserId());
		if ($user === null) {
			throw new NoUserException();
		}

		// A Fediverse actor is public by definition — creating one is the act of
		// publishing a profile — so anything short of an explicitly private
		// display name is fair to federate. Requiring SCOPE_PUBLISHED meant
		// that on a default install (where the scope is SCOPE_FEDERATED)
		// nobody's display name ever reached their actor, and every client fell
		// back to `preferredUsername`, i.e. the raw Nextcloud user id.
		$publishable = [
			IAccountManager::SCOPE_PUBLISHED,
			IAccountManager::SCOPE_FEDERATED,
		];

		try {
			$account = $this->accountManager->getAccount($user);
			$displayNameProperty = $account->getProperty(IAccountManager::PROPERTY_DISPLAYNAME);

			if (!in_array($displayNameProperty->getScope(), $publishable, true)) {
				// deliberately kept private or instance-local: leave whatever
				// the actor already carries alone rather than publishing it
				return;
			}

			$displayName = $displayNameProperty->getValue();
		} catch (Exception $e) {
			// The property could not be read at all, which is not the same as
			// the user having asked for privacy: fall back to the display name
			// the rest of Nextcloud already shows.
			$this->logger->warning(
				'could not read the account of a local actor, falling back to its display name',
				['userId' => $actor->getUserId(), 'exception' => $e]
			);
			$displayName = (string)$user->getDisplayName();
		}

		if ($displayName !== '') {
			$actor->setName($displayName);
		}
	}

	/**
	 * A Fediverse handle is not a Nextcloud user id.
	 *
	 * It ends up in `preferredUsername`, in the actor's URL and in the
	 * `acct:` WebFinger subject, so it has to survive being put in a URL and
	 * has to match what other implementations accept — Mastodon and most
	 * others allow letters, digits and `_`, with `.` and `-` inside. A user id
	 * that does not (an LDAP UUID, an e-mail address, anything with a space)
	 * produces an actor no remote server can resolve, which used to happen
	 * silently because this method validated nothing at all.
	 *
	 * @throws InvalidHandleException
	 */
	private function checkActorUsername(string $username): void {
		if ($username === '' || strlen($username) > self::HANDLE_MAX_LENGTH) {
			throw new InvalidHandleException(
				'a Fediverse handle must be between 1 and ' . self::HANDLE_MAX_LENGTH . ' characters'
			);
		}

		if (preg_match(self::HANDLE_PATTERN, $username) !== 1) {
			throw new InvalidHandleException(
				'"' . $username . '" cannot be used as a Fediverse handle: only letters, digits and '
				. 'underscore are allowed, with dot and dash inside'
			);
		}
	}

	/**
	 * Derive a usable Fediverse handle from a Nextcloud user id.
	 *
	 * Called by the paths that create an account on the user's behalf, where
	 * nobody got to choose a handle. The user id is used as-is when it already
	 * qualifies, so existing installs keep the handles they have; otherwise it
	 * is folded down to something resolvable and a numeric suffix is added
	 * until it is free.
	 */
	public function generateHandleFromUserId(string $userId): string {
		$candidate = $userId;
		try {
			$this->checkActorUsername($candidate);
		} catch (InvalidHandleException $e) {
			$candidate = strtolower($userId);
			// anything outside the allowed set becomes an underscore, then
			// runs of underscores collapse and the edges are trimmed
			$candidate = preg_replace('/[^a-z0-9_.-]+/', '_', $candidate) ?? '';
			$candidate = preg_replace('/_{2,}/', '_', $candidate) ?? '';
			$candidate = trim($candidate, '_.-');
			$candidate = substr($candidate, 0, self::HANDLE_MAX_LENGTH);

			if ($candidate === '' || preg_match(self::HANDLE_PATTERN, $candidate) !== 1) {
				// nothing usable survived (a purely non-latin id, say): fall
				// back to something stable and unique for this user
				$candidate = 'user_' . substr(hash('sha256', $userId), 0, 12);
			}

			$this->logger->notice(
				'user id cannot be used as a Fediverse handle, derived one instead',
				['userId' => $userId, 'handle' => $candidate]
			);
		}

		return $this->firstFreeHandle($candidate);
	}

	/**
	 * `$candidate` if no actor holds it, else `$candidate` with the lowest
	 * numeric suffix that is free.
	 */
	private function firstFreeHandle(string $candidate): string {
		$handle = $candidate;
		for ($i = 2; $i < 100; $i++) {
			try {
				$this->actorsRequest->getFromUsername($handle);
			} catch (ActorDoesNotExistException $e) {
				return $handle;
			}

			$suffix = '_' . $i;
			$handle = substr($candidate, 0, self::HANDLE_MAX_LENGTH - strlen($suffix)) . $suffix;
		}

		// a hundred collisions on one derived handle is not a real install
		return substr($candidate, 0, self::HANDLE_MAX_LENGTH - 13) . '_' . substr(
			hash('sha256', $candidate . microtime()), 0, 12
		);
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
