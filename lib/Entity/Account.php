<?php
declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="social_account")
 */
class Account {
	/**
	 * @ORM\Id
	 * @ORM\Column(type="integer")
	 * @ORM\GeneratedValue
	 */
    private ?int $id = null;

	/**
	 * @ORM\Column(name="user_name", type="string", nullable=false)
	 */
	private string $userName = "";

	/**
	 * @ORM\Column(name="user_id", type="string", nullable=true)
	 */
	private ?string $userId = null;

	/**
	 * @ORM\ManyToOne
	 * @ORM\JoinColumn(name="domain", referencedColumnName="domain", nullable=true)
	 */
	private ?Instance $instance;

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
	private string $actorType = "person";

	/**
	 * @ORM\Column(nullable=false)
	 */
	private bool $discoverable = true;

	/**
	 * @ORM\OneToMany(targetEntity="Follow", mappedBy="account", fetch="EXTRA_LAZY")
	 * @var Collection<Follow>
	 */
	private Collection $follow;

	/**
	 * @ORM\OneToMany(targetEntity="Follow", mappedBy="targetAccount", fetch="EXTRA_LAZY")
	 * @var Collection<Follow>
	 */
	private Collection $followedBy;

	/**
	 * @ORM\OneToMany(targetEntity="Follow", mappedBy="account", fetch="EXTRA_LAZY")
	 * @var Collection<Block>
	 */
	private Collection $block;

	/**
	 * @ORM\OneToMany(targetEntity="Follow", mappedBy="targetAccount", fetch="EXTRA_LAZY")
	 * @var Collection<Block>
	 */
	private Collection $blockedBy;

	private Collection $activeMentions;

	public function __construct() {
		$this->block = new ArrayCollection();
		$this->blockedBy = new ArrayCollection();
		$this->follow = new ArrayCollection();
		$this->followedBy = new ArrayCollection();
		$this->updatedAt = new \DateTime();
		$this->createdAt = new \DateTime();
		$this->instance = new Instance();
	}

	public function getId(): int {
		return $this->id;
	}

	public function getUserId(): string {
		return $this->userId;
	}

	public function setUserId(string $userId): void {
		$this->userId = $userId;
	}

	public function getUserName(): string {
		return $this->userName;
	}

	public function setUserName(string $userName): void {
		$this->userName = $userName;
	}

	public function getInstance(): Instance {
		return $this->instance;
	}

	public function setInstance(Instance $instance): void {
		$this->instance = $instance;
	}

	public function getPrivateKey(): string {
		return $this->privateKey;
	}

	public function setPrivateKey(string $privateKey): void {
		$this->privateKey = $privateKey;
	}

	public function getPublicKey(): string {
		return $this->publicKey;
	}

	public function setPublicKey(string $publicKey): void {
		$this->publicKey = $publicKey;
	}

	public function getCreatedAt(): \DateTime {
		return $this->createdAt;
	}

	public function setCreatedAt(\DateTime $createdAt): void {
		$this->createdAt = $createdAt;
	}

	public function getUpdatedAt(): \DateTime {
		return $this->updatedAt;
	}

	public function setUpdatedAt(\DateTime $updatedAt): void {
		$this->updatedAt = $updatedAt;
	}

	public function getUri(): string {
		return $this->uri;
	}

	public function setUri(string $uri): void {
		$this->uri = $uri;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function setUrl(string $url): void {
		$this->url = $url;
	}

	public function isLocked(): bool {
		return $this->locked;
	}

	public function setLocked(bool $locked): void {
		$this->locked = $locked;
	}

	public function getAvatarRemoteUrl(): string {
		return $this->avatarRemoteUrl;
	}

	public function setAvatarRemoteUrl(string $avatarRemoteUrl): void {
		$this->avatarRemoteUrl = $avatarRemoteUrl;
	}

	public function getHeaderRemoteUrl(): string {
		return $this->headerRemoteUrl;
	}

	public function setHeaderRemoteUrl(string $headerRemoteUrl): void {
		$this->headerRemoteUrl = $headerRemoteUrl;
	}

	public function getLastWebfingeredAt(): ?\DateTimeInterface {
		return $this->lastWebfingeredAt;
	}

	public function setLastWebfingeredAt(?\DateTimeInterface $lastWebfingeredAt): void {
		$this->lastWebfingeredAt = $lastWebfingeredAt;
	}

	public function getInboxUrl(): string {
		return $this->inboxUrl;
	}

	public function setInboxUrl(string $inboxUrl): void {
		$this->inboxUrl = $inboxUrl;
	}

	public function getOutboxUrl(): string {
		return $this->outboxUrl;
	}

	public function setOutboxUrl(string $outboxUrl): void {
		$this->outboxUrl = $outboxUrl;
	}

	public function getSharedInboxUrl(): string {
		return $this->sharedInboxUrl;
	}

	public function setSharedInboxUrl(string $sharedInboxUrl): void {
		$this->sharedInboxUrl = $sharedInboxUrl;
	}

	public function getFollowersUrl(): string {
		return $this->followersUrl;
	}

	public function setFollowersUrl(string $followersUrl): void {
		$this->followersUrl = $followersUrl;
	}

	public function getProtocol(): string {
		return $this->protocol;
	}

	public function setProtocol(string $protocol): void {
		$this->protocol = $protocol;
	}

	public function isMemorial(): bool {
		return $this->memorial;
	}

	public function setMemorial(bool $memorial): void {
		$this->memorial = $memorial;
	}

	public function getFields(): array {
		return $this->fields;
	}

	public function setFields(array $fields): void {
		$this->fields = $fields;
	}

	public function getActorType(): string {
		return $this->actorType;
	}

	public function setActorType(string $actorType): void {
		$this->actorType = $actorType;
	}

	public function isDiscoverable(): bool {
		return $this->discoverable;
	}

	public function setDiscoverable(bool $discoverable): void {
		$this->discoverable = $discoverable;
	}

	public function getFollow(): Collection {
		return $this->follow;
	}

	public function setFollow(Collection $follow): void {
		$this->follow = $follow;
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

	public function setBlock(Collection $block): void {
		$this->block = $block;
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

	public function possiblyStale() {
		return $this->lastWebfingeredAt === null || $this->lastWebfingeredAt->diff((new \DateTime('now')))->days > 1;
	}
}
