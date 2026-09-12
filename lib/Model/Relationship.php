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
	/**
	 * Boosts from an account the viewer follows do reach the home timeline, and
	 * nothing here can turn them off per account. `false` described a setting
	 * this app does not have, and a client that reads it offers "show boosts"
	 * as the action for a timeline that already shows them.
	 */
	private bool $showingReblogs = true;
	/**
	 * Whether the viewer is notified of this account's new posts — Mastodon's
	 * per-account bell. There is no such subscription here, so the honest
	 * answer is that nobody is being notified.
	 */
	private bool $notifying = false;
	private bool $followedBy = false;
	private bool $blocking = false;
	private bool $blockedBy = false;
	private bool $muting = false;
	private bool $mutingNotifications = false;
	private bool $requested = false;
	private bool $domainBlocking = false;
	private bool $endorsed = false;
	private bool $requestedBy = false;
	private string $note = '';
	private array $languages = [];

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

	public function setRequestedBy(bool $requestedBy): self {
		$this->requestedBy = $requestedBy;

		return $this;
	}

	public function isRequestedBy(): bool {
		return $this->requestedBy;
	}

	/**
	 * The private note the viewer keeps about this account, written through
	 * `POST /api/v1/accounts/{id}/note` and read by nobody else — not by the
	 * account it is about, and never by another instance.
	 */
	public function setNote(string $note): self {
		$this->note = $note;

		return $this;
	}

	public function getNote(): string {
		return $this->note;
	}

	/**
	 * Which languages the viewer wants from this account. Mastodon sends null
	 * for "all of them", which is the only thing this app offers.
	 */
	public function setLanguages(array $languages): self {
		$this->languages = $languages;

		return $this;
	}

	public function getLanguages(): array {
		return $this->languages;
	}

	/**
	 * Mastodon's Relationship entity.
	 *
	 * `id` is a string, like every other id on the wire: a strongly typed
	 * client that declares `id: String` (Ivory, Mona and anything else built on
	 * Swift's Codable) fails to decode an integer, so the follow/block/mute
	 * button state broke after every action that returns one of these.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->getId(),
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
			'endorsed' => $this->isEndorsed(),
			'requested_by' => $this->isRequestedBy(),
			'note' => $this->getNote(),
			'languages' => ($this->getLanguages() === []) ? null : $this->getLanguages(),
		];
	}
}
