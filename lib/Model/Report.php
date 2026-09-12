<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * One moderation report, filed locally over the client API or received from a
 * remote instance as a Flag activity. Serialises as the Mastodon Report entity.
 */
class Report implements JsonSerializable {
	public const CATEGORY_SPAM = 'spam';
	public const CATEGORY_LEGAL = 'legal';
	public const CATEGORY_VIOLATION = 'violation';
	public const CATEGORY_OTHER = 'other';

	public const CATEGORIES = [
		self::CATEGORY_SPAM,
		self::CATEGORY_LEGAL,
		self::CATEGORY_VIOLATION,
		self::CATEGORY_OTHER,
	];

	private int $id = 0;
	private string $actorId = '';
	private string $accountId = '';
	/** @var string[] */
	private array $statusIds = [];
	private string $comment = '';
	private string $category = self::CATEGORY_OTHER;
	private bool $local = true;
	private bool $resolved = false;
	private bool $forwarded = false;
	private int $creation = 0;
	private ?Person $targetAccount = null;

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setActorId(string $actorId): self {
		$this->actorId = $actorId;

		return $this;
	}

	public function getActorId(): string {
		return $this->actorId;
	}

	public function setAccountId(string $accountId): self {
		$this->accountId = $accountId;

		return $this;
	}

	public function getAccountId(): string {
		return $this->accountId;
	}

	/**
	 * @param string[] $statusIds
	 */
	public function setStatusIds(array $statusIds): self {
		$this->statusIds = array_values(array_filter($statusIds, fn ($id): bool => is_string($id) && $id !== ''));

		return $this;
	}

	/**
	 * @return string[]
	 */
	public function getStatusIds(): array {
		return $this->statusIds;
	}

	public function setComment(string $comment): self {
		$this->comment = $comment;

		return $this;
	}

	public function getComment(): string {
		return $this->comment;
	}

	public function setCategory(string $category): self {
		$this->category = in_array($category, self::CATEGORIES, true) ? $category : self::CATEGORY_OTHER;

		return $this;
	}

	public function getCategory(): string {
		return $this->category;
	}

	public function setLocal(bool $local): self {
		$this->local = $local;

		return $this;
	}

	public function isLocal(): bool {
		return $this->local;
	}

	/**
	 * Whether the Flag was delivered to the instance the reported account is
	 * on. Only ever true for a local report about a remote account: there is
	 * nowhere to forward a report about one of our own, and a report that
	 * arrived as a Flag is not ours to pass on.
	 */
	public function setForwarded(bool $forwarded): self {
		$this->forwarded = $forwarded;

		return $this;
	}

	public function isForwarded(): bool {
		return $this->forwarded;
	}

	public function setResolved(bool $resolved): self {
		$this->resolved = $resolved;

		return $this;
	}

	public function isResolved(): bool {
		return $this->resolved;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	public function setTargetAccount(?Person $targetAccount): self {
		$this->targetAccount = $targetAccount;

		return $this;
	}

	public function getTargetAccount(): ?Person {
		return $this->targetAccount;
	}

	/**
	 * The Mastodon Report entity.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->getId(),
			'action_taken' => $this->isResolved(),
			'action_taken_at' => null,
			'category' => $this->getCategory(),
			'comment' => $this->getComment(),
			'forwarded' => $this->isForwarded(),
			'created_at' => gmdate('Y-m-d\TH:i:s', $this->getCreation()) . '.000Z',
			'status_ids' => $this->getStatusIds(),
			'rule_ids' => null,
			'target_account' => $this->getTargetAccount(),
		];
	}
}
