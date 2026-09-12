<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
use OCA\Social\Tools\IQueryRow;
use OCA\Social\Traits\TDetails;
use OCP\IURLGenerator;
use OCP\Server;

/**
 * Class Actor
 *
 * @package OCA\Social\Model\ActivityPub
 */
class Person extends ACore implements IQueryRow, JsonSerializable {
	use TDetails;

	public const TYPE = 'Person';

	/**
	 * The actor types the fediverse serves an automated account as, and which
	 * Mastodon reports as `bot`: a `Service` is a bot account, an `Application`
	 * is a server's own actor. Nothing else on the wire states it.
	 */
	private const BOT_TYPES = [Service::TYPE, Application::TYPE];

	public const LINK_VIEWER = 'viewer';
	public const LINK_REMOTE = 'remote';
	public const LINK_LOCAL = 'local';

	private string $userId = '';
	private string $name = '';
	private string $preferredUsername = '';
	private string $displayName = '';
	private string $description = '';
	private string $publicKey = '';
	private string $privateKey = '';
	private int $creation = 0;
	private int $deleted = 0;
	private string $account = '';
	private string $following = '';
	private string $followers = '';
	private string $inbox = '';
	private string $outbox = '';
	private string $sharedInbox = '';
	private string $featured = '';
	private string $avatar = '';
	private string $header = '';
	private bool $locked = false;
	private array $emojis = [];
	private bool $bot = false;
	private bool $discoverable = false;
	private bool $indexable = false;
	private string $privacy = 'public';
	private bool $sensitive = false;
	private string $language = 'en';
	private int $avatarVersion = -1;
	private int $headerVersion = -1;
	private string $viewerLink = '';

	/** @var string[] */
	private array $alsoKnownAs = [];

	/** The actor this account moved to, if it has; `movedTo` on the wire. */
	private string $movedTo = '';

	/** The cached copy of the `movedTo` actor, when whoever hydrated this one had it. */
	private ?Person $movedToActor = null;

	/** @var array[] profile metadata, [['name' => string, 'value' => string], …] */
	private array $fields = [];

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
	public function setUserId(string $userId): self {
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
	public function setPreferredUsername(string $preferredUsername): self {
		$this->preferredUsername = $preferredUsername;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getDisplayName(): string {
		if ($this->displayName === '') {
			return $this->getPreferredUsername();
		}

		return $this->displayName;
	}

	/**
	 * @param string $displayName
	 *
	 * @return $this
	 */
	public function setDisplayName(string $displayName): self {
		$this->displayName = $displayName;

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
	 * @return Person
	 */
	public function setDescription(string $description): self {
		$this->description = $description;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getAvatar(): string {
		if ($this->hasIcon()) {
			return $this->getIcon()
				->getUrl();
		}

		return $this->avatar;
	}

	/**
	 * @param string $avatar
	 *
	 * @return $this
	 */
	public function setAvatar(string $avatar): self {
		$this->avatar = $avatar;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getHeader(): string {
		if ($this->header === '') {
			return $this->getAvatar();
		}

		return $this->header;
	}

	/**
	 * @param string $header
	 *
	 * @return $this
	 */
	public function setHeader(string $header): self {
		$this->header = $header;

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
	public function setPublicKey(string $publicKey): self {
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
	public function setPrivateKey(string $privateKey): self {
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
	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getDeleted(): int {
		return $this->deleted;
	}

	/**
	 * @param int $deleted
	 *
	 * @return Person
	 */
	public function setDeleted(int $deleted): self {
		$this->deleted = $deleted;

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
	public function setFollowing(string $following): self {
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
	public function setFollowers(string $followers): self {
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
	public function setAccount(string $account): self {
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
	public function setInbox(string $inbox): self {
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
	public function setOutbox(string $outbox): self {
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
	public function setSharedInbox(string $sharedInbox): self {
		$this->sharedInbox = $sharedInbox;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getName(): string {
		if ($this->name === '') {
			return $this->preferredUsername;
		}

		return $this->name;
	}

	/**
	 * @param string $name
	 *
	 * @return Person
	 */
	public function setName(string $name): self {
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
	public function setFeatured(string $featured): self {
		$this->featured = $featured;

		return $this;
	}

	/**
	 * @return bool
	 */
	/**
	 * @return array[] Mastodon CustomEmoji entries used in the display name/bio
	 */
	public function getEmojis(): array {
		return $this->emojis;
	}

	public function setEmojis(array $emojis): self {
		$this->emojis = $emojis;

		return $this;
	}

	public function isLocked(): bool {
		return $this->locked;
	}

	/**
	 * @param bool $locked
	 *
	 * @return Person
	 */
	public function setLocked(bool $locked): self {
		$this->locked = $locked;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isBot(): bool {
		return $this->bot;
	}

	/**
	 * @param bool $bot
	 *
	 * @return Person
	 */
	public function setBot(bool $bot): self {
		$this->bot = $bot;

		// The flag and the actor type are one fact. A peer reads `type`, a
		// client reads `bot`, and an account marked automated here that went on
		// serving `Person` would have told the two of them different things.
		// Only ever between these two: an `Application` stays an `Application`.
		if ($this->getType() === self::TYPE || $this->getType() === Service::TYPE) {
			$this->setType($bot ? Service::TYPE : self::TYPE);
		}

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isDiscoverable(): bool {
		return $this->discoverable;
	}

	/**
	 * @param bool $discoverable
	 *
	 * @return Person
	 */
	public function setDiscoverable(bool $discoverable): self {
		$this->discoverable = $discoverable;

		return $this;
	}

	/**
	 * Whether the account's public posts may be full-text indexed by other
	 * servers (`toot:indexable`). Opt-in, like `discoverable`.
	 */
	public function isIndexable(): bool {
		return $this->indexable;
	}

	public function setIndexable(bool $indexable): self {
		$this->indexable = $indexable;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getPrivacy(): string {
		return $this->privacy;
	}

	/**
	 * @param string $privacy
	 *
	 * @return Person
	 */
	public function setPrivacy(string $privacy): self {
		$this->privacy = $privacy;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isSensitive(): bool {
		return $this->sensitive;
	}

	/**
	 * @param bool $sensitive
	 *
	 * @return Person
	 */
	public function setSensitive(bool $sensitive): self {
		$this->sensitive = $sensitive;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getLanguage(): string {
		return $this->language;
	}

	/**
	 * @param string $language
	 *
	 * @return $this
	 */
	public function setLanguage(string $language): self {
		$this->language = $language;

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
	public function setAvatarVersion(int $avatarVersion): self {
		$this->avatarVersion = $avatarVersion;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getHeaderVersion(): int {
		return $this->headerVersion;
	}

	/**
	 * @param int $headerVersion
	 *
	 * @return Person
	 */
	public function setHeaderVersion(int $headerVersion): self {
		$this->headerVersion = $headerVersion;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getViewerLink(): string {
		return $this->viewerLink;
	}

	/**
	 * @param string $viewerLink
	 *
	 * @return Person
	 */
	public function setViewerLink(string $viewerLink): self {
		$this->viewerLink = $viewerLink;

		return $this;
	}

	/**
	 * The actor ids this actor also answers to — a `Move` is only valid when
	 * its target lists the moving actor here.
	 *
	 * @return string[]
	 */
	public function getAlsoKnownAs(): array {
		return $this->alsoKnownAs;
	}

	/**
	 * @param string[] $alsoKnownAs
	 */
	public function setAlsoKnownAs(array $alsoKnownAs): self {
		$this->alsoKnownAs = array_values(array_filter($alsoKnownAs, 'is_string'));

		return $this;
	}

	/**
	 * The id of the actor this account moved to; empty while it has not.
	 */
	public function getMovedTo(): string {
		return $this->movedTo;
	}

	public function setMovedTo(string $movedTo): self {
		$this->movedTo = $movedTo;

		return $this;
	}

	/**
	 * The account behind `movedTo`, when it is known. What the client entity
	 * shows as `moved`; without it, a stub is derived from the id alone.
	 */
	public function getMovedToActor(): ?Person {
		return $this->movedToActor;
	}

	public function setMovedToActor(?Person $movedToActor): self {
		$this->movedToActor = $movedToActor;

		return $this;
	}

	/**
	 * Profile metadata (the name/value table under the bio), federated as
	 * `attachment` entries of type PropertyValue. Mastodon-compatible: at most
	 * four fields, both halves required.
	 *
	 * @return array[]
	 */
	public function getFields(): array {
		return $this->fields;
	}

	/**
	 * @param array[] $fields [['name' => string, 'value' => string], …]
	 */
	public function setFields(array $fields): self {
		$this->fields = [];
		foreach ($fields as $field) {
			if (!is_array($field)) {
				continue;
			}
			$name = trim((string)($field['name'] ?? ''));
			$value = trim((string)($field['value'] ?? ''));
			if ($name === '' || $value === '') {
				continue;
			}
			$this->fields[] = [
				'name' => mb_substr($name, 0, 255),
				'value' => mb_substr($value, 0, 500)
			];
			if (count($this->fields) === 4) {
				break;
			}
		}

		return $this;
	}

	/**
	 * @return array[] the PropertyValue entries of an actor's `attachment`
	 */
	private function extractFieldsFromAttachment(array $data): array {
		$fields = [];
		foreach ($this->getArray('attachment', $data, []) as $entry) {
			if (!is_array($entry) || ($entry['type'] ?? '') !== 'PropertyValue') {
				continue;
			}
			$fields[] = [
				'name' => (string)($entry['name'] ?? ''),
				'value' => (string)($entry['value'] ?? '')
			];
		}

		return $fields;
	}

	/**
	 * @param array $data
	 *
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function import(array $data) {
		parent::import($data);
		$this->setEmojis($this->extractEmojisFromTag($data));
		$this->setDescription($this->validate(ACore::AS_CONTENT, 'summary', $data, ''))
			->setPreferredUsername($this->validate(ACore::AS_USERNAME, 'preferredUsername', $data, ''))
			->setPublicKey($this->get('publicKey.publicKeyPem', $data))
			->setSharedInbox($this->validate(ACore::AS_URL, 'endpoints.sharedInbox', $data))
			->setName($this->validate(ACore::AS_USERNAME, 'name', $data, ''))
			->setAccount($this->validate(ACore::AS_ACCOUNT, 'account', $data, ''))
			->setInbox($this->validate(ACore::AS_URL, 'inbox', $data, ''))
			->setOutbox($this->validate(ACore::AS_URL, 'outbox', $data, ''))
			->setFollowers($this->validate(ACore::AS_URL, 'followers', $data, ''))
			->setFollowing($this->validate(ACore::AS_URL, 'following', $data, ''))
			->setFeatured($this->validate(ACore::AS_URL, 'featured', $data, ''))
			->setAlsoKnownAs($this->getArray('alsoKnownAs', $data, []))
			->setMovedTo($this->validate(ACore::AS_URL, 'movedTo', $data, ''));
		$this->setLocked($this->getBool('manuallyApprovesFollowers', $data, false));
		$this->setBot(in_array($this->getType(), self::BOT_TYPES, true));
		// Mastodon serialises an unset preference as null; getBool() reads that as the default
		$this->setDiscoverable($this->getBool('discoverable', $data, false));
		$this->setIndexable($this->getBool('indexable', $data, false));
		$this->setFields($this->extractFieldsFromAttachment($data));

		/** @var Image $icon */
		$icon = AP::instance()->getItemFromType(Image::TYPE);
		$icon->setParent($this);
		$icon->import($this->getArray('icon', $data, []));

		if ($icon->getType() === Image::TYPE) {
			$this->setIcon($icon);
		}

		$image = $this->get('image.url', $data, '');
		if ($image !== '') {
			$this->setHeader($image);
		}
	}

	/**
	 * @param array $data
	 *
	 * @return $this
	 */
	#[\Override]
	public function importFromLocal(array $data): self {
		parent::importFromLocal($data);

		$this->setId($this->get('url', $data));
		$this->setPreferredUsername($this->get('username', $data));
		$this->setAccount($this->get('acct', $data));
		$this->setDisplayName($this->get('display_name', $data));
		$this->setLocked($this->getBool('locked', $data));
		$this->setBot($this->getBool('bot', $data));
		$this->setDiscoverable($this->getBool('discoverable', $data));
		$this->setIndexable($this->getBool('indexable', $data));
		$this->setMovedTo($this->get('moved.url', $data, ''));
		$this->setDescription($this->get('note', $data));
		$this->setUrl($this->get('url', $data));

		$this->setAvatar($this->get('avatar', $data));
		$this->setHeader($this->get('header', $data));

		$this->setPrivacy($this->get('source.privacy', $data));
		$this->setSensitive($this->getBool('source.sensitive', $data));
		$this->setLanguage($this->get('source.language', $data));
		$this->setFields($this->getArray('fields', $data, []));

		try {
			$dTime = new DateTime($this->get('created_at', $data, 'yesterday'));
			$this->setCreation($dTime->getTimestamp());
		} catch (Exception $e) {
		}

		$count = [
			'followers' => $this->getInt('followers_count', $data),
			'following' => $this->getInt('following_count', $data),
			'post' => $this->getInt('statuses_count', $data),
			'last_post_creation' => $this->get('last_status_at', $data)
		];
		$this->setDetailArray('count', $count);

		return $this;
	}

	/**
	 * @param array $data
	 */
	#[\Override]
	public function importFromDatabase(array $data) {
		parent::importFromDatabase($data);

		// The parent flattens `summary` the way a plain-text field arriving
		// from the wire has to be flattened. A stored bio is not arriving from
		// anywhere: it is the text the user typed, and everything that renders
		// it escapes it (`bioAsHtml()`), so flattening it again protects
		// nothing and destroys plenty — `strip_tags()` reads a bare `<` as the
		// start of a tag and eats the rest of the line, so a bio saying
		// `Maths: a<b and b>c` came back out of the database as `Maths: ac`.
		// A cached remote actor's column holds HTML, but nothing renders
		// `getSummary()` for one: remote bios are read from `description`,
		// which the sanitising pass below still produces.
		$this->setSummary((string)($data['summary'] ?? ''));

		// the columns of a local actor row; a cache row has none of them and
		// carries the same facts in its source document, read just below
		$this->setLocked($this->getInt('locked', $data, 0) === 1);
		$this->setDiscoverable($this->getInt('discoverable', $data, 0) === 1);
		$this->setIndexable($this->getInt('indexable', $data, 0) === 1);
		$this->setMovedTo($this->get('moved_to', $data, ''));
		$storedAliases = json_decode($this->get('also_known_as', $data, ''), true);
		if (is_array($storedAliases)) {
			$this->setAlsoKnownAs($storedAliases);
		}

		$source = json_decode($this->getSource(), true);
		if (is_array($source)) {
			$image = $this->get('image.url', $source, '');
			if ($image !== '') {
				$this->setHeader($image);
			}
			$this->setAlsoKnownAs($this->getArray('alsoKnownAs', $source, []));
			$this->setMovedTo($this->validate(self::AS_URL, 'movedTo', $source, $this->getMovedTo()));
			$this->setLocked($this->getBool('manuallyApprovesFollowers', $source, $this->isLocked()));
			$this->setDiscoverable($this->getBool('discoverable', $source, $this->isDiscoverable()));
			$this->setIndexable($this->getBool('indexable', $source, $this->isIndexable()));
			$this->setEmojis($this->extractEmojisFromTag($source));
			$this->setFields($this->extractFieldsFromAttachment($source));
		}

		// A cached remote row keeps the actor type it was served as, which is
		// the only thing that ever said the account is automated. A local row
		// has the flag itself, because nothing else stores it: the type this
		// app serves *is* the flag, so reading it back off the type would be
		// circular.
		if (array_key_exists('bot', $data)) {
			$this->setBot($this->getInt('bot', $data, 0) === 1);
		} else {
			$this->setBot(in_array($this->getType(), self::BOT_TYPES, true));
		}

		// local actor rows carry the canonical fields in their own column
		$storedFields = json_decode($this->get('fields', $data, ''), true);
		if (is_array($storedFields)) {
			$this->setFields($storedFields);
		}

		$this->setPreferredUsername($this->validate(self::AS_USERNAME, 'preferred_username', $data, ''))
			->setUserId($this->get('user_id', $data, ''))
			->setName($this->validate(self::AS_USERNAME, 'name', $data, ''))
			->setDescription($this->validate(self::AS_CONTENT, 'summary', $data))
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
			$cTime = new DateTime($this->get('creation', $data, 'yesterday'));
			$this->setCreation($cTime->getTimestamp());
		} catch (Exception $e) {
		}

		try {
			$deletedValue = $this->get('deleted', $data);
			if ($deletedValue === '' || $deletedValue === '0000-00-00 00:00:00') {
				return;
			}
			$dTime = new DateTime($deletedValue);
			$deleted = $dTime->getTimestamp();
			if ($deleted > 0) {
				$this->setDeleted($deleted);
			}
		} catch (Exception $e) {
		}
	}

	/**
	 * @return array
	 */
	/**
	 * The bio of a local actor as HTML.
	 *
	 * A local bio is stored as the plain text the user typed (see
	 * `AccountService::setSummary()`), because that is the only form a client
	 * can put back in an edit box. `summary` on the wire and `note` on the
	 * client API are HTML everywhere else in the Fediverse, though — Mastodon
	 * renders both, and this app's own profile card hands `note` to `v-html` —
	 * so the stored text is rendered here: a blank line starts a paragraph, a
	 * single newline is a `<br />`, and everything else is escaped, which is
	 * what a bio reading `bees & goats` needs to survive the trip.
	 *
	 * A remote bio never goes through this: it arrived as HTML and was
	 * sanitized on import, and is passed on as it is.
	 */
	private function bioAsHtml(): string {
		$plain = trim(str_replace(["\r\n", "\r"], "\n", $this->getSummary()));
		if ($plain === '') {
			return '';
		}

		$html = '';
		foreach (preg_split('/\n{2,}/', $plain) ?: [] as $paragraph) {
			$escaped = htmlspecialchars($paragraph, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$html .= '<p>' . str_replace("\n", '<br />', $escaped) . '</p>';
		}

		return $html;
	}

	#[\Override]
	public function exportAsActivityPub(): array {
		if ($this->getPublicKey() !== '') {
			$this->setDisplayW3ContextSecurity(true);
		}

		$data = [
			'aliases' => [
				$this->getUrlSocial() . '@' . $this->getPreferredUsername(),
				$this->getUrlSocial() . 'users/' . $this->getPreferredUsername()
			],
			'preferredUsername' => $this->getPreferredUsername(),
			'name' => $this->getName(),
			'inbox' => $this->getInbox(),
			'outbox' => $this->getOutbox(),
			'account' => $this->getAccount(),
			'following' => $this->getFollowing(),
			'followers' => $this->getFollowers(),
			'endpoints' => ['sharedInbox' => $this->getSharedInbox()],
			'publicKey' => [
				'id' => $this->getId() . '#main-key',
				'owner' => $this->getId(),
				'publicKeyPem' => $this->getPublicKey()
			]
		];

		// a local bio is stored as plain text, and `summary` is HTML on the wire
		if ($this->isLocal() && ($bio = $this->bioAsHtml()) !== '') {
			$data['summary'] = $bio;
		}

		$data['manuallyApprovesFollowers'] = $this->isLocked();
		// Both default to false on the receiving side (Mastodon), so an actor
		// that omits them is never listed in a directory, suggested, or
		// full-text searched: they have to be said out loud to opt in.
		$data['discoverable'] = $this->isDiscoverable();
		$data['indexable'] = $this->isIndexable();

		if ($this->getFeatured() !== '') {
			$data['featured'] = $this->getFeatured();
		}

		if ($this->getAlsoKnownAs() !== []) {
			$data['alsoKnownAs'] = $this->getAlsoKnownAs();
		}

		if ($this->getMovedTo() !== '') {
			$data['movedTo'] = $this->getMovedTo();
		}

		if ($this->fields !== []) {
			$data['attachment'] = array_map(
				static fn (array $field): array => [
					'type' => 'PropertyValue',
					'name' => $field['name'],
					'value' => $field['value']
				],
				$this->fields
			);
		}

		if ($this->hasIcon()) {
			$icon = $this->getIcon();
			$data['icon'] = [
				'type' => $icon->getType(),
				'mediaType' => $icon->getMediaType(),
				'url' => $icon->getUrl()
			];
		}

		if ($this->header !== '') {
			$data['image'] = [
				'type' => 'Image',
				'mediaType' => 'image/jpeg',
				'url' => $this->header
			];
		}

		$result = array_merge(
			parent::exportAsActivityPub(),
			$data
		);

		if ($this->isCompleteDetails()) {
			$result['details'] = $this->getDetailsAll();
			$result['viewerLink'] = $this->getViewerLink();
		}

		return $result;
	}

	/**
	 * The `source` half of Mastodon's CredentialAccount: an account's own
	 * editable copy of its settings.
	 *
	 * Deliberately *not* part of `exportAsLocal()`. It used to be, so every
	 * Account entity this app emitted carried it — somebody else's profile,
	 * a search result, a page of followers, an anonymous read — and with it
	 * `follow_requests_count`, which is nobody's business but the account's
	 * own. Mastodon puts `source` on exactly two routes, and so does this:
	 * `verify_credentials` and `update_credentials`, which are the two that
	 * know they are answering the account itself.
	 *
	 * @return array<string, mixed>
	 */
	public function exportSourceAsLocal(): array {
		$details = $this->getDetailsAll();

		return [
			'privacy' => $this->getPrivacy(),
			'sensitive' => $this->isSensitive(),
			'language' => $this->getLanguage(),
			// the account's own editable copy, so the bio is the plain text it
			// is stored as, never the rendered HTML
			'note' => $this->isLocal() ? $this->getSummary() : $this->getDescription(),
			'fields' => $this->getFields(),
			'follow_requests_count' => $this->getInt('count.follow_requests', $details),
		];
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function exportAsLocal(): array {
		if ($this->hasIcon()) {
			$avatar = $this->getIcon()->getMediaUrl(Server::get(IURLGenerator::class));
		}

		$headerUrl = $this->getHeader();
		$details = $this->getDetailsAll();
		$fields = array_map(
			static fn (array $field): array => array_merge($field, ['verified_at' => null]),
			$this->getFields()
		);
		$result
			= [
				'id' => (string)$this->getNid(),
				'username' => $this->getPreferredUsername(),
				'acct' => $this->isLocal() ? $this->getPreferredUsername() : $this->getAccount(),
				'display_name' => $this->getName(),
				'locked' => $this->isLocked(),
				'bot' => $this->isBot(),
				'discoverable' => $this->isDiscoverable(),
				'indexable' => $this->isIndexable(),
				'group' => false,
				'created_at' => gmdate('Y-m-d\TH:i:s', $this->getCreation()) . '.000Z',
				'note' => $this->isLocal() ? $this->bioAsHtml() : $this->getDescription(),
				'url' => $this->getId(),
				'avatar' => $avatar ?? $this->getAvatar(),
				'avatar_static' => $avatar ?? $this->getAvatar(),
				'header' => $headerUrl,
				'header_static' => $headerUrl,
				'followers_count' => $this->getInt('count.followers', $details),
				'following_count' => $this->getInt('count.following', $details),
				'statuses_count' => $this->getInt('count.post', $details),
				// null, not '', while nothing was posted: a date-or-null field in Mastodon's entity
				'last_status_at' => $this->get('last_post_creation', $details) !== ''
					? $this->get('last_post_creation', $details) : null,
				'emojis' => $this->getEmojis(),
				'fields' => $fields
			];

		if ($this->getMovedTo() !== '') {
			$result['moved'] = $this->exportMovedAccount();
		}

		return array_merge(parent::exportAsLocal(), $result);
	}

	/**
	 * The `moved` account entity: what a client shows as the "has moved"
	 * banner and follows through to. The cached target when it is known; a
	 * stub derived from the id otherwise, complete in shape so a client that
	 * expects a full Account entity does not choke on it. Never chains: a
	 * target that itself moved on is not followed.
	 */
	private function exportMovedAccount(): array {
		$target = $this->movedToActor;
		if ($target === null) {
			$target = new Person();
			$path = (string)parse_url($this->movedTo, PHP_URL_PATH);
			$username = basename(rtrim($path, '/'));
			$username = ltrim($username, '@');
			$host = (string)parse_url($this->movedTo, PHP_URL_HOST);
			$target->setId($this->movedTo);
			$target->setUrl($this->movedTo);
			$target->setPreferredUsername($username)
				->setAccount($host === '' ? $username : $username . '@' . $host);
		}

		$export = $target->exportAsLocal();
		unset($export['moved']);

		return $export;
	}
}
