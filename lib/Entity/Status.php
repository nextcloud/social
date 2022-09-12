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
use OCA\Social\Service\ActivityPub;

/**
 * @ORM\Entity
 * @ORM\Table(name="social_status")
 * @ORM\HasLifecycleCallbacks
 */
class Status {
	const STATUS_PUBLIC = "public";
	const STATUS_UNLISTED = "unlisted";
	const STATUS_PRIVATE = "private";
	const STATUS_DIRECT = "direct";

	/**
	 * @ORM\Id
	 * @ORM\Column(type="bigint")
	 * @ORM\GeneratedValue
	 */
    private string $id = "";

	/**
	 * @ORM\Column(type="string", nullable=true)
	 */
	private ?string $uri = null;

	/**
	 * @ORM\Column(type="text", nullable=false)
	 */
	private string $text = "";

	/**
	 * @ORM\Column(name="created_at", type="datetime", nullable=false)
	 */
	private \DateTime $createdAt;

	/**
	 * @ORM\Column(name="updated_at", type="datetime", nullable=false)
	 */
	private \DateTime $updatedAt;

	/**
	 * @ORM\ManyToOne
	 * @ORM\JoinColumn(name="in_reply_to_id", referencedColumnName="id", nullable=true)
	 */
	private ?Status $inReplyTo = null;

	/**
	 * @ORM\Column(type="string", nullable=true)
	 */
	private ?string $url = null;

	/**
	 * @ORM\Column(name="`sensitive`")
	 */
	private bool $sensitive = false;

	/**
	 * @ORM\Column
	 */
	private int $visibility = 0;

	/**
	 * @ORM\Column(name="spoiler_text", type="text", nullable=false)
	 */
	private string $spoilerText = "";

	/**
	 * @ORM\Column(type="boolean", nullable=false)
	 */
	private bool $reply = false;

	/**
	 * @ORM\Column(type="string", nullable=false)
	 */
	private string $language = "en";

	/**
	 * @ORM\Column(name="conversation_id", type="bigint", nullable=true)
	 */
	private ?string $conversationId = null;

	/**
	 * @ORM\Column(name="local", type="boolean", nullable=false)
	 */
	private bool $local = false;

	/**
	 * @ORM\ManyToOne
	 * @ORM\JoinColumn(nullable=false)
	 */
	private ?Account $account = null;

	/**
	 * @ORM\ManyToOne
	 * @ORM\JoinColumn(name="application_id", referencedColumnName="id", nullable=true)
	 */
	private ?Application $application = null;

	/**
	 * @ORM\Column(name="in_reply_to_account_id", type="bigint", nullable=true)
	 */
	private ?string $inReplyToAccountId = null;

	/**
	 * @ORM\Column(name="poll_id", type="bigint", nullable=true)
	 */
	private ?string $pollId = null;

	/**
	 * @ORM\Column(name="deleted_at", type="datetime", nullable=true)
	 */
	private ?\DateTime $deletedAt = null;

	/**
	 * @ORM\Column(name="edited_at", type="datetime", nullable=true)
	 */
	private ?\DateTime $editedAt = null;

	/**
	 * @ORM\Column(nullable=false)
	 */
	private bool $trendable = false;

	/**
	 * @var list<int>
	 * @ORM\Column(name="ordered_media_attachment_ids", type="array", nullable=false)
	 */
	private array $orderedMediaAttachmentIds = [];

	/**
	 * @ORM\OneToMany(targetEntity="Mention", mappedBy="status")
	 */
	private Collection $mentions;

	public function __construct() {
		$this->mentions = new ArrayCollection();
		$this->createdAt = new \DateTime();
		$this->updatedAt = new \DateTime();
	}

	/**
	 * @ORM\PostPersist
	 */
	public function generateUri(): void {
		if ($this->uri !== null) {
			return;
		}

		$this->uri = ActivityPub\TagManager::getInstance()->uriFor($this);
	}

	public function getId(): string {
		return $this->id;
	}

	public function setId(string $id): void {
		$this->id = $id;
	}

	public function getUri(): ?string {
		return $this->uri;
	}

	public function setUri(?string $uri): void {
		$this->uri = $uri;
	}

	public function getText(): string {
		return $this->text;
	}

	public function setText(string $text): void {
		$this->text = $text;
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

	public function getInReplyTo(): ?Status {
		return $this->inReplyTo;
	}

	public function setInReplyTo(?Status $inReplyTo): void {
		$this->inReplyTo = $inReplyTo;
	}

	public function getReblogOf(): ?Status {
		return $this->reblogOf;
	}

	public function setReblogOf(?Status $reblogOf): void {
		$this->reblogOf = $reblogOf;
	}

	public function getUrl(): ?string {
		return $this->url;
	}

	public function setUrl(?string $url): void {
		$this->url = $url;
	}

	public function isSensitive(): bool {
		return $this->sensitive;
	}

	public function setSensitive(bool $sensitive): void {
		$this->sensitive = $sensitive;
	}

	public function getVisibility(): int {
		return $this->visibility;
	}

	public function setVisibility(int $visibility): void {
		$this->visibility = $visibility;
	}

	public function getSpoilerText(): string {
		return $this->spoilerText;
	}

	public function setSpoilerText(string $spoilerText): void {
		$this->spoilerText = $spoilerText;
	}

	public function isReply(): bool {
		return $this->reply;
	}

	public function setReply(bool $reply): void {
		$this->reply = $reply;
	}

	public function getLanguage(): string {
		return $this->language;
	}

	public function setLanguage(string $language): void {
		$this->language = $language;
	}

	public function getConversationId(): ?string {
		return $this->conversationId;
	}

	public function setConversationId(?string $conversationId): void {
		$this->conversationId = $conversationId;
	}

	public function isLocal(): bool {
		return $this->local;
	}

	public function setLocal(bool $local): void {
		$this->local = $local;
	}

	public function getAccount(): Account {
		return $this->account;
	}

	public function setAccount(Account $account): void {
		$this->account = $account;
	}

	public function getApplication(): ?Application {
		return $this->application;
	}

	public function setApplication(?Application $application): void {
		$this->application = $application;
	}

	public function getInReplyToAccountId(): ?string {
		return $this->inReplyToAccountId;
	}

	public function setInReplyToAccountId(?string $inReplyToAccountId): void {
		$this->inReplyToAccountId = $inReplyToAccountId;
	}

	public function getPollId(): ?string {
		return $this->pollId;
	}

	public function setPollId(?string $pollId): void {
		$this->pollId = $pollId;
	}

	public function getDeletedAt(): ?\DateTime {
		return $this->deletedAt;
	}

	public function setDeletedAt(?\DateTime $deletedAt): void {
		$this->deletedAt = $deletedAt;
	}

	public function getEditedAt(): ?\DateTime {
		return $this->editedAt;
	}

	public function setEditedAt(?\DateTime $editedAt): void {
		$this->editedAt = $editedAt;
	}

	public function isTrendable(): bool {
		return $this->trendable;
	}

	public function setTrendable(bool $trendable): void {
		$this->trendable = $trendable;
	}

	/**
	 * @return int[]
	 */
	public function getOrderedMediaAttachmentIds(): array {
		return $this->orderedMediaAttachmentIds;
	}

	/**
	 * @param int[] $orderedMediaAttachmentIds
	 */
	public function setOrderedMediaAttachmentIds(array $orderedMediaAttachmentIds): void {
		$this->orderedMediaAttachmentIds = $orderedMediaAttachmentIds;
	}

	public function getMentions(): Collection {
		return $this->mentions;
	}

	/**
	 * @return Collection<Mention>
	 */
	public function getActiveMentions(): Collection {
		$criteria = Criteria::create();
		$criteria->where(Criteria::expr()->eq('silent', false));
		return $this->mentions->matching($criteria);
	}

	public function setMentions(Collection $mentions): void {
		$this->mentions = $mentions;
	}

	public function isReblog(): bool {
		return $this->reblogOf !== null;
	}

	public function toMastodonApi(): array {
		return [
			'id' => $this->id,
			'created_at' => $this->createdAt->format(\DateTimeInterface::ISO8601),
			'in_reply_to_id' => $this->inReplyTo ? $this->inReplyTo->getId() : null,
			'in_reply_to_account_id' => $this->inReplyTo ? $this->inReplyTo->getAccount()->getId() : null,
			'sensitive' => $this->sensitive,
			'spoiler_text' => $this->spoilerText,
			'visibility' => $this->visibility,
			'language' => $this->language,
			'uri' => $this->uri,
			'url' => $this->url,
			'replies_count' => 0,
			'reblogs_count' => 0,
			'favourites_count' => 0,
			'favourited' => false,
			'reblogged' => false,
			'muted' => false,
			'bookmarked' => false,
			'content' => $this->text,
			'reblog' => $this->reblogOf,
			'application' => $this->application ? $this->application->toMastodonApi() : null,
			'account' => $this->account->toMastodonApi(),
		];
	}
}
