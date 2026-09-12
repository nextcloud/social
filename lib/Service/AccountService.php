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
use OCA\Social\Exceptions\InvalidActionException;
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
use OCA\Social\Model\ActivityPub\Stream;
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
	 * How long a bio may be, in characters. Mastodon's `note` is capped at 500
	 * and truncates what a client sends beyond it; a longer one would be cut
	 * on every server that shows it anyway.
	 */
	private const SUMMARY_MAX_LENGTH = 500;

	/**
	 * Where an account's default post visibility is kept, as a per-user app
	 * preference. It is a preference of the person, not a property of the
	 * actor: nothing federates it, and the actor row has no column for it.
	 */
	private const DEFAULT_PRIVACY = 'default_privacy';

	/**
	 * Age, in days, past which `blindKeyRotation()` would renew an actor's key pair.
	 * The rotation is not currently scheduled; the constant exists so the method does
	 * not fatal on an undefined constant if it is ever called.
	 */
	public const KEY_PAIR_LIFESPAN = 60;

	public function __construct(
		private IUserManager $userManager,
		private IUserSession $userSession,
		private IAccountManager $accountManager,
		private ActorsRequest $actorsRequest,
		private FollowsRequest $followsRequest,
		private StreamRequest $streamRequest,
		private ActorService $actorService,
		private ActivityService $activityService,
		private DocumentService $documentService,
		private SignatureService $signatureService,
		private ConfigService $configService,
		private AccessBlockService $accessBlockService,
		private LoggerInterface $logger,
	) {
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
	 * Refuses a fediverse identity to a Nextcloud account at a blocked email
	 * domain.
	 *
	 * Mastodon's email-domain block polices its sign-up. There is no sign-up
	 * here — the server decides who gets a Nextcloud account — so this is the
	 * one decision left that is this app's to make, and it is the same
	 * question one step later: whether this instance publishes a fediverse
	 * identity for whoever is behind that address. It matters on a server with
	 * open registration, which is where a throwaway-address domain turns up.
	 *
	 * An account with no email address is allowed: there is nothing to check
	 * it against, and a server that stores no addresses would otherwise hand
	 * out no fediverse accounts at all.
	 *
	 * @throws InvalidHandleException
	 */
	private function assertEmailDomainIsAllowed(string $userId): void {
		$user = $this->userManager->get($userId);
		$email = ($user === null) ? '' : (string)$user->getEMailAddress();
		if ($email === '' || !$this->accessBlockService->isBlockedEmail($email)) {
			return;
		}

		$this->logger->info('refused a fediverse account to a blocked email domain', [
			'userId' => $userId,
		]);

		throw new InvalidHandleException(
			'this server does not create fediverse accounts for addresses at that domain'
		);
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
		$this->assertEmailDomainIsAllowed($userId);

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
		$interface = AP::instance()->getInterfaceFromType(Person::TYPE);
		$interface->delete($actor);

		$this->federateActorDelete($actor);
	}

	/**
	 * Tells the fediverse an account is gone.
	 *
	 * Its own method because deletion is not the only thing that ends an
	 * account here: a moderator suspending a local account deletes everything
	 * it posted and stops serving its actor, and a suspension that federated
	 * nothing left every remote instance holding a full copy of an account this
	 * one had decided to remove — the takedown stopped at our own edge.
	 */
	public function federateActorDelete(Person $actor): void {
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
	 * Stores the directory flags of this user's actor and refreshes the actor
	 * cache so they reach the actor document and the account entity.
	 *
	 * Accepted keys, each a bool (the string forms clients send — `'true'`,
	 * `'1'` — are read as such): `discoverable`, whether the account may be
	 * listed in directories and suggestions; `indexable`, whether its public
	 * posts may be full-text indexed by other servers. Both are opt-in, like
	 * Mastodon's. A key that is not sent is left as it is; anything else in
	 * `$flags` is ignored, so the caller can hand over a whole
	 * `update_credentials` body.
	 *
	 * @param array<string, mixed> $flags
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function setActorFlags(string $userId, array $flags): void {
		$actor = $this->getActorFromUserId($userId);

		$changed = false;
		if (array_key_exists('discoverable', $flags)) {
			$actor->setDiscoverable($this->flag($flags['discoverable']));
			$changed = true;
		}
		if (array_key_exists('indexable', $flags)) {
			$actor->setIndexable($this->flag($flags['indexable']));
			$changed = true;
		}
		// this one changes the actor's *type* as well — see Person::setBot()
		if (array_key_exists('bot', $flags)) {
			$actor->setBot($this->flag($flags['bot']));
			$changed = true;
		}

		if (!$changed) {
			return;
		}

		$this->actorsRequest->updateFlags($actor);
		$this->cacheLocalActorByUsername($actor->getPreferredUsername());
	}

	/**
	 * The account's display name, as a Mastodon client edits it.
	 *
	 * It belongs to the Nextcloud account rather than to the actor — the actor
	 * copies it, and `updateCacheLocalActorName()` is what carries it over — so
	 * this writes it where it lives and re-caches. A backend that will not have
	 * it written (LDAP, SAML, anything provisioned from elsewhere) says so, and
	 * that is a refusal the client should see rather than a silent success: the
	 * name it shows afterwards would be the old one either way, and only one of
	 * those two outcomes tells the user why.
	 *
	 * @throws InvalidActionException when the user's backend owns the name
	 * @throws NoUserException
	 */
	public function setDisplayName(string $userId, string $displayName): void {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			throw new NoUserException();
		}

		if (!$user->canChangeDisplayName()) {
			throw new InvalidActionException(
				'the display name of this account is managed outside Nextcloud and cannot be changed here'
			);
		}

		$user->setDisplayName($displayName);
		$this->cacheLocalActorByUsername($this->getActorFromUserId($userId)->getPreferredUsername());
	}

	/** A bool from whatever form a client put in a JSON or form body. */
	private function flag(mixed $value): bool {
		if (is_bool($value)) {
			return $value;
		}

		return in_array($value, [1, '1', 'true', 'on'], true);
	}

	/**
	 * Stores the actor ids this user's actor also answers to (`alsoKnownAs`)
	 * and refreshes the actor cache so the list reaches the actor document.
	 * A remote server accepts a Move *into* here only when this list names the
	 * moving account, so it has to be set before the move is started there.
	 *
	 * @param string[] $alsoKnownAs
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function setAlsoKnownAs(string $userId, array $alsoKnownAs): void {
		$actor = $this->getActorFromUserId($userId);
		$actor->setAlsoKnownAs($alsoKnownAs);
		$this->actorsRequest->updateAlsoKnownAs($actor);
		$this->cacheLocalActorByUsername($actor->getPreferredUsername());
	}

	/**
	 * Records where this user's actor moved to (`movedTo`; empty to clear) and
	 * refreshes the actor cache so the actor document and the account entity
	 * say so. The Move itself is federated by MigrationService.
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function setMovedTo(string $userId, string $movedTo): void {
		$actor = $this->getActorFromUserId($userId);
		$actor->setMovedTo($movedTo);
		$this->actorsRequest->updateMovedTo($actor);
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
	 * Stores the bio of this user's actor, refreshes the actor cache so it
	 * reaches the actor document and the account entity, and tells the
	 * followers about it.
	 *
	 * A bio is kept as **plain text**: it is what the user typed, it is what
	 * `source.note` has to hand back to a client that opens an edit box on it,
	 * and it is the only form that cannot carry markup into a reader's
	 * timeline. It is stored exactly as it was typed — every path that renders
	 * it escapes it (`Person::bioAsHtml()` for `summary` on the wire and `note`
	 * on the client API), so there is nothing left for a stripping pass here to
	 * protect and a great deal for it to break: `strip_tags()` reads a bare
	 * `<` as the start of a tag and eats the rest of the line, which turned
	 * `Maths: a<b and b>c` into `Maths: ac`. What is stored is cut to
	 * `SUMMARY_MAX_LENGTH` characters rather than refused, the way
	 * `Person::setFields()` caps a field.
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function setSummary(string $userId, string $summary): void {
		$actor = $this->getActorFromUserId($userId);
		$actor->setSummary($this->plainSummary($summary));
		$this->actorsRequest->updateSummary($actor);
		$this->cacheLocalActorByUsername($actor->getPreferredUsername());
		$this->federateActorUpdate($actor);
	}

	/**
	 * The visibility a post of this account gets when the client sends none —
	 * Mastodon's `source.privacy`, which a client reads at login and offers as
	 * the preselected audience in its composer. A stored value that is not one
	 * this app posts with is ignored rather than guessed at.
	 */
	public function getDefaultPrivacy(string $userId): string {
		$stored = (string)$this->configService->getValueForUser($userId, self::DEFAULT_PRIVACY);

		return Stream::isKnownClientVisibility($stored) ? $stored : Stream::TYPE_PUBLIC;
	}

	/**
	 * @throws InvalidActionException when it is not a visibility a post can have
	 */
	public function setDefaultPrivacy(string $userId, string $privacy): void {
		$privacy = strtolower(trim($privacy));
		if (!Stream::isKnownClientVisibility($privacy)) {
			throw new InvalidActionException(
				'unknown visibility: ' . $privacy . ' (one of '
				. implode(', ', Stream::clientVisibilities()) . ')'
			);
		}

		$this->configService->setValueForUser($userId, self::DEFAULT_PRIVACY, $privacy);
	}

	/** A bio as it is stored: as typed, normalised newlines, length-capped. */
	private function plainSummary(string $summary): string {
		$summary = trim(str_replace(["\r\n", "\r"], "\n", $summary));

		if (mb_strlen($summary) > self::SUMMARY_MAX_LENGTH) {
			$summary = rtrim(mb_substr($summary, 0, self::SUMMARY_MAX_LENGTH));
		}

		return $summary;
	}

	/**
	 * Tells the followers that the actor document changed, with an
	 * `Update{Person}` — the path `LocalController` already uses after a new
	 * header image, signed here with the local actor's own key.
	 *
	 * A failure is logged and swallowed: the change is stored either way, and
	 * a remote server picks it up when it next refreshes the actor.
	 */
	private function federateActorUpdate(Person $actor): void {
		try {
			$update = clone $actor;
			$update->addInstancePath(
				new InstancePath(
					$actor->getId(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
				)
			);
			$this->activityService->updateActivity($actor, $update);
		} catch (Exception $e) {
			$this->logger->warning(
				'could not tell the followers that a local actor changed',
				['actor' => $actor->getId(), 'exception' => $e]
			);
		}
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
			$displayName = $user->getDisplayName();
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
