<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;
use OCA\Social\Tools\Traits\TArrayTools;

class Relationship implements JsonSerializable {
	use TArrayTools;

	private int $id;
	private bool $following = false;
	private bool $showingReblogs = false;
	private bool $notifying = false;
	private bool $followedBy = false;
	private bool $blocking = false;
	private bool $blockedBy = false;
	private bool $muting = false;
	private bool $mutingNotifications = false;
	private bool $requested = false;
	private bool $domainBlocking = false;
	private bool $endorsed = false;

	public function __construct(int $id = 0) {
		$this->id = $id;
	}

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): int {
		return $this->id;
	}


	public function setFollowing(bool $following): self {
		$this->following = $following;

		return $this;
	}

	public function isFollowing(): bool {
		return $this->following;
	}

	public function setShowingReblogs(bool $showingReblogs): self {
		$this->showingReblogs = $showingReblogs;

		return $this;
	}

	public function isShowingReblogs(): bool {
		return $this->showingReblogs;
	}

	public function setNotifying(bool $notifying): self {
		$this->notifying = $notifying;

		return $this;
	}

	public function isNotifying(): bool {
		return $this->notifying;
	}

	public function setFollowedBy(bool $followedBy): self {
		$this->followedBy = $followedBy;

		return $this;
	}

	public function isFollowedBy(): bool {
		return $this->followedBy;
	}

	public function setBlocking(bool $blocking): self {
		$this->blocking = $blocking;

		return $this;
	}

	public function isBlocking(): bool {
		return $this->blocking;
	}

	public function setBlockedBy(bool $blockedBy): self {
		$this->blockedBy = $blockedBy;

		return $this;
	}

	public function isBlockedBy(): bool {
		return $this->blockedBy;
	}

	public function setMuting(bool $muting): self {
		$this->muting = $muting;

		return $this;
	}

	public function isMuting(): bool {
		return $this->muting;
	}

	public function setMutingNotifications(bool $mutingNotifications): self {
		$this->mutingNotifications = $mutingNotifications;

		return $this;
	}

	public function isMutingNotifications(): bool {
		return $this->mutingNotifications;
	}

	public function setRequested(bool $requested): self {
		$this->requested = $requested;

		return $this;
	}

	public function isRequested(): bool {
		return $this->requested;
	}

	public function setDomainBlocking(bool $domainBlocking): self {
		$this->domainBlocking = $domainBlocking;

		return $this;
	}

	public function isDomainBlocking(): bool {
		return $this->domainBlocking;
	}

	public function setEndorsed(bool $endorsed): self {
		$this->endorsed = $endorsed;

		return $this;
	}

	public function isEndorsed(): bool {
		return $this->endorsed;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'following' => $this->isFollowing(),
			'showing_reblogs' => $this->isShowingReblogs(),
			'notifying' => $this->isNotifying(),
			'followed_by' => $this->isFollowedBy(),
			'blocking' => $this->isBlocking(),
			'blocked_by' => $this->isBlockedBy(),
			'muting' => $this->isMuting(),
			'muting_notifications' => $this->isMutingNotifications(),
			'requested' => $this->isRequested(),
			'domain_blocking' => $this->isDomainBlocking(),
			'endorsed' => $this->isEndorsed()
		];
	}
}
