<?php
declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping as ORM;
use OCA\Social\Service\FollowOption;
use OCA\Social\InstanceUtils;
use OCP\IRequest;

/**
 * @ORM\Entity
 * @ORM\Table(name="social_account")
 */
class Account {
	const REPRESENTATIVE_ID = '-99';

	const TYPE_APPLICATION = 'Application';
	const TYPE_PERSON = 'Person';
	const TYPE_GROUP = 'Group';
	const TYPE_ORGANIZATION = 'Organization';
	const TYPE_SERVICE = 'Service';

	/**
	 * @ORM\Id
	 * @ORM\Column(type="bigint")
	 * @ORM\GeneratedValue
	 */
    private ?string $id = null;

	/**
	 * Username of the user e.g. alice from alice@cloud.social
	 *
	 * @ORM\Column(name="user_name", nullable=false)
	 */
	private string $userName = "";

	/**
	 * Internal userId of the user
	 *
	 * Only set for local users.
	 *
	 * @ORM\Column(name="user_id", nullable=true, unique=true)
	 */
	private ?string $userId = null;

	/**
	 * Display name: e.g. "Alice Müller"
	 * @ORM\Column(nullable=true)
	 */
	private ?string $name = null;

	/**
	 * @ORM\ManyToOne
	 * @ORM\JoinColumn(name="domain", referencedColumnName="domain", nullable=true)
	 */
	private ?Instance $instance = null;

	/**
	 * @ORM\Column(name="private_key", type="text", nullable=false)
	 */
	private string $privateKey = "";

	/**
	 * @ORM\Column(name="public_key", type="text", nullable=false)
	 */
	private string $publicKey = "";

	/**
	 * @ORM\Column(name="created_at", type="datetime", nullable=false)
	 */
	private \DateTime $createdAt;

	/**
	 * @ORM\Column(name="updated_at", type="datetime", nullable=false)
	 */
	private \DateTime $updatedAt;

	/**
	 * @ORM\Column(type="string", nullable=false)
	 */
	private string $uri = "";

	/**
	 * @ORM\Column(type="string", nullable=false)
	 */
	private string $url = "";

	/**
	 * @ORM\Column(type="boolean", nullable=false)
	 */
	private bool $locked = false;

	/**
	 * @ORM\Column(name="avatar_remote_url", type="string", nullable=false)
	 */
	private string $avatarRemoteUrl = "";

	/**
	 * @ORM\Column(name="header_remote_url", type="string", nullable=false)
	 */
	private string $headerRemoteUrl = "";

	/**
	 * @ORM\Column(name="last_webfingered_at", type="datetime", nullable=true)
	 */
	private ?\DateTimeInterface $lastWebfingeredAt = null;

	/**
	 * @ORM\Column(name="inbox_url", type="string", nullable=false)
	 */
	private string $inboxUrl = "";

	/**
	 * @ORM\Column(name="outbox_url", type="string", nullable=false)
	 */
	private string $outboxUrl = "";

	/**
	 * @ORM\Column(name="shared_inbox_url", type="string", nullable=false)
	 */
	private string $sharedInboxUrl = "";

	/**
	 * @ORM\Column(name="followers_url", type="string", nullable=false)
	 */
	private string $followersUrl = "";

	/**
	 * @ORM\Column(name="protocol", type="string", nullable=false)
	 */
	private string $protocol = "ostatus";

	/**
	 * @ORM\Column(type="boolean", nullable=false)
	 */
	private bool $memorial = false;

	/**
	 * @ORM\Column(type="json", nullable=false)
	 */
	private array $fields = [];

	/**
	 * @ORM\Column(type="string", nullable=false)
	 */
	private string $actorType = self::TYPE_PERSON;

	/**
	 * @ORM\Column(nullable=false)
	 */
	private bool $discoverable = true;

	/**
	 * @ORM\OneToMany(targetEntity="Follow", mappedBy="account", fetch="EXTRA_LAZY", cascade={"persist", "remove"})
	 * @var Collection<Follow>
	 */
	private Collection $follow;

	/**
	 * @ORM\OneToMany(targetEntity="Follow", mappedBy="targetAccount", fetch="EXTRA_LAZY", cascade={"persist", "remove"})
	 * @var Collection<Follow>
	 */
	private Collection $followedBy;

	/**
	 * @ORM\OneToMany(targetEntity="FollowRequest", mappedBy="account", fetch="EXTRA_LAZY", cascade={"persist", "remove"})
	 * @var Collection<FollowRequest>
	 */
	private Collection $followRequest;

	/**
	 * @ORM\OneToMany(targetEntity="FollowRequest", mappedBy="targetAccount", fetch="EXTRA_LAZY", cascade={"persist", "remove"})
	 * @var Collection<FollowRequest>
	 */
	private Collection $followRequestFrom;

	/**
	 * @ORM\OneToMany(targetEntity="Follow", mappedBy="account", fetch="EXTRA_LAZY", cascade={"persist", "remove"})
	 * @var Collection<Block>
	 */
	private Collection $block;

	/**
	 * @ORM\OneToMany(targetEntity="Follow", mappedBy="targetAccount", fetch="EXTRA_LAZY", cascade={"persist", "remove"})
	 * @var Collection<Block>
	 */
	private Collection $blockedBy;

	public function __construct() {
		$this->block = new ArrayCollection();
		$this->blockedBy = new ArrayCollection();
		$this->follow = new ArrayCollection();
		$this->followRequest = new ArrayCollection();
		$this->followRequestFrom = new ArrayCollection();
		$this->followedBy = new ArrayCollection();
		$this->updatedAt = new \DateTime();
		$this->createdAt = new \DateTime();
	}

	static public function newLocal(string $userId = null, string $userName = null, string $displayName = null): self {
		$account = new Account();
		if ($userId !== null) {
			$account->setUserId($userId);
			if ($userName !== null) {
				$account->setUserName($userName);
			} else {
				$account->setUserName($userId);
			}
			if ($displayName !== null) {
				$account->setName($displayName);
			} else {
				$account->setName($account->getUserName());
			}
		}
		$account->generateKeys();
		return $account;
	}

	public function generateKeys(): self {
		if (!$this->isLocal() || ($this->publicKey !== '' && $this->privateKey !== '')) {
			return $this;
		}

		$res = openssl_pkey_new([
			"digest_alg" => "rsa",
			"private_key_bits" => 2048,
			"private_key_type" => OPENSSL_KEYTYPE_RSA,
		]);

		openssl_pkey_export($res, $privateKey);
		$publicKey = openssl_pkey_get_details($res)['key'];

		$this->setPublicKey($publicKey);
		$this->setPrivateKey($privateKey);

		return $this;
	}

	public function getId(): string {
		return $this->id;
	}

	public function setRepresentative(): self {
		$this->userId = '__self';
		return $this;
	}

	public function getUserId(): ?string {
		return $this->userId;
	}

	public function setUserId(string $userId): self {
		$this->userId = $userId;
		return $this;
	}

	public function getUserName(): string {
		return $this->userName;
	}

	public function setUserName(string $userName): self {
		$this->userName = $userName;
		return $this;
	}

	public function getName(): string {
		return $this->name;
	}

	public function setName(string $displayName): self {
		$this->name = $displayName;
		return $this;
	}

	public function getInstance(): ?Instance {
		return $this->instance;
	}

	public function setInstance(Instance $instance): self {
		$this->instance = $instance;
		return $this;
	}

	public function getPrivateKey(): string {
		return $this->privateKey;
	}

	public function setPrivateKey(string $privateKey): self {
		$this->privateKey = $privateKey;
		return $this;
	}

	public function getPublicKey(): string {
		return $this->publicKey;
	}

	public function setPublicKey(string $publicKey): self {
		$this->publicKey = $publicKey;
		return $this;
	}

	public function getCreatedAt(): \DateTime {
		return $this->createdAt;
	}

	public function setCreatedAt(\DateTime $createdAt): self {
		$this->createdAt = $createdAt;
		return $this;
	}

	public function getUpdatedAt(): \DateTime {
		return $this->updatedAt;
	}

	public function setUpdatedAt(\DateTime $updatedAt): self {
		$this->updatedAt = $updatedAt;
		return $this;
	}

	public function getUri(): string {
		return $this->uri;
	}

	public function setUri(string $uri): self {
		$this->uri = $uri;
		return $this;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function setUrl(string $url): self {
		$this->url = $url;
		return $this;
	}

	public function isLocked(): bool {
		return $this->locked;
	}

	public function setLocked(bool $locked): self {
		$this->locked = $locked;
		return $this;
	}

	public function getAvatarRemoteUrl(): string {
		return $this->avatarRemoteUrl;
	}

	public function setAvatarRemoteUrl(string $avatarRemoteUrl): self {
		$this->avatarRemoteUrl = $avatarRemoteUrl;
		return $this;
	}

	public function getHeaderRemoteUrl(): string {
		return $this->headerRemoteUrl;
	}

	public function setHeaderRemoteUrl(string $headerRemoteUrl): self {
		$this->headerRemoteUrl = $headerRemoteUrl;
		return $this;
	}

	public function getLastWebfingeredAt(): ?\DateTimeInterface {
		return $this->lastWebfingeredAt;
	}

	public function setLastWebfingeredAt(?\DateTimeInterface $lastWebfingeredAt): self {
		$this->lastWebfingeredAt = $lastWebfingeredAt;
		return $this;
	}

	public function getInboxUrl(): string {
		return $this->inboxUrl;
	}

	public function setInboxUrl(string $inboxUrl): self {
		$this->inboxUrl = $inboxUrl;
		return $this;
	}

	public function getOutboxUrl(): string {
		return $this->outboxUrl;
	}

	public function setOutboxUrl(string $outboxUrl): self {
		$this->outboxUrl = $outboxUrl;
		return $this;
	}

	public function getSharedInboxUrl(): string {
		return $this->sharedInboxUrl;
	}

	public function setSharedInboxUrl(string $sharedInboxUrl): self {
		$this->sharedInboxUrl = $sharedInboxUrl;
		return $this;
	}

	public function getFollowersUrl(): string {
		return $this->followersUrl;
	}

	public function setFollowersUrl(string $followersUrl): self {
		$this->followersUrl = $followersUrl;
		return $this;
	}

	public function getProtocol(): string {
		return $this->protocol;
	}

	public function setProtocol(string $protocol): self {
		$this->protocol = $protocol;
		return $this;
	}

	public function isMemorial(): bool {
		return $this->memorial;
	}

	public function setMemorial(bool $memorial): self {
		$this->memorial = $memorial;
		return $this;
	}

	public function getFields(): array {
		return $this->fields;
	}

	public function setFields(array $fields): self {
		$this->fields = $fields;
		return $this;
	}

	public function getActorType(): string {
		return $this->actorType;
	}

	public function setActorType(string $actorType): self {
		$this->actorType = $actorType;
		return $this;
	}

	public function isDiscoverable(): bool {
		return $this->discoverable;
	}

	public function setDiscoverable(bool $discoverable): self {
		$this->discoverable = $discoverable;
		return $this;
	}

	public function getFollow(): Collection {
		return $this->follow;
	}

	public function setFollow(Collection $follow): self {
		$this->follow = $follow;
		return $this;
	}

	public function getFollowedBy(): Collection {
		return $this->followedBy;
	}

	public function setFollowedBy(Collection $followedBy): void {
		$this->followedBy = $followedBy;
	}

	public function getBlock(): Collection {
		return $this->block;
	}

	public function setBlock(Collection $block): self {
		$this->block = $block;
		return $this;
	}

	public function getBlockedBy(): Collection {
		return $this->blockedBy;
	}

	public function setBlockedBy(Collection $blockedBy): void {
		$this->blockedBy = $blockedBy;
	}

	public function isLocal(): bool {
		return $this->getInstance() === null;
	}

	public function getDomain(): ?string {
		return $this->getInstance() !== null ? $this->getInstance()->getDomain() : null;
	}

	public function getAccountName(): string {
		return $this->isLocal() ? $this->getUserName() : $this->getUserName() . '@' . $this->getDomain();
	}

	public function possiblyStale(): bool {
		return $this->lastWebfingeredAt === null || $this->lastWebfingeredAt->diff((new \DateTime('now')))->days > 1;
	}

	/**
	 * Check whether this account follow the $targetAccount
	 */
	public function following(Account $targetAccount): bool {
		$criteria = Criteria::create();
		$criteria->where(Criteria::expr()->eq('account', $targetAccount));
		return !$this->follow->matching($criteria)->isEmpty();
	}

	/**
	 * Check whether this account created a follow request to $targetAccount
	 */
	public function followRequested(Account $targetAccount): bool {
		$criteria = Criteria::create();
		$criteria->where(Criteria::expr()->eq('account', $targetAccount));
		return !$this->followRequest->matching($criteria)->isEmpty();
	}

	/**
	 * Add a new follower to this account
	 */
	public function follow(Account $account, bool $notify = false, bool $showReblogs = true): Follow {
		$follow = new Follow();
		$follow->setTargetAccount($account);
		$follow->setAccount($this);
		$follow->setNotify($notify);
		$follow->setShowReblogs($showReblogs);
		$this->followedBy->add($follow);
		return $follow;
	}

	public function getFollowRequest() {
		return $this->followRequest;
	}

	public function setFollowRequest($followRequest): void {
		$this->followRequest = $followRequest;
	}

	public function getFollowRequestFrom(): Collection {
		return $this->followRequestFrom;
	}

	public function setFollowRequestFrom(Collection $followRequestFrom): self {
		$this->followRequestFrom = $followRequestFrom;
		return $this;
	}
}
