<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;

/**
 * Mastodon's Conversation entity: one thread of direct messages, as the
 * Conversations screen of Tusky, Ivory, Ice Cubes and Phanpy draws it.
 *
 * `id` is the nid of the thread's root post — the message with no parent, or
 * the topmost parent this instance stores. This app has no conversation row to
 * take an id from, and the id has to mean the same thing on the next request
 * or `POST /api/v1/conversations/{id}/read` would mark something else read:
 * the root is the one message of a thread that every message in it agrees on,
 * and its nid is already stable, already unique and already the id this API
 * hands out for that post. See ConversationService for what that costs.
 *
 * `accounts` are the other participants — never the viewer, as on Mastodon,
 * which is what lets a client title the row "Bob" rather than "You and Bob".
 * A conversation with nobody else in it (a note to self) therefore has an
 * empty `accounts`, which is also what Mastodon answers.
 *
 * `last_status` may be null: Mastodon declares it nullable and a client has to
 * cope with it. Here it is null only if the page was built from a thread whose
 * newest message could not be read back.
 */
class Conversation implements JsonSerializable {
	private int $id = 0;
	private string $rootId = '';
	private bool $unread = false;
	/** @var Person[] */
	private array $accounts = [];
	private ?Stream $lastStatus = null;

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): int {
		return $this->id;
	}

	/** The ActivityPub id of the thread root, which `id` is the nid of. */
	public function setRootId(string $rootId): self {
		$this->rootId = $rootId;

		return $this;
	}

	public function getRootId(): string {
		return $this->rootId;
	}

	public function setUnread(bool $unread): self {
		$this->unread = $unread;

		return $this;
	}

	public function isUnread(): bool {
		return $this->unread;
	}

	/**
	 * @param Person[] $accounts
	 */
	public function setAccounts(array $accounts): self {
		$this->accounts = array_values($accounts);

		return $this;
	}

	/**
	 * @return Person[]
	 */
	public function getAccounts(): array {
		return $this->accounts;
	}

	public function setLastStatus(?Stream $lastStatus): self {
		$this->lastStatus = $lastStatus;

		return $this;
	}

	public function getLastStatus(): ?Stream {
		return $this->lastStatus;
	}

	/**
	 * The nid the page cursor moves on: the newest message of the thread, not
	 * the thread's own id. Conversations are ordered by their newest message,
	 * so that is the only value a cursor can be compared against.
	 */
	public function getLastStatusNid(): int {
		return ($this->lastStatus === null) ? 0 : $this->lastStatus->getNid();
	}

	/**
	 * Exactly Mastodon's four keys.
	 *
	 * `id` is a string here and an int in the row, as every other id on this
	 * wire is: a client that declares `id: String` cannot decode a number.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		foreach ($this->accounts as $account) {
			$account->setExportFormat(ACore::FORMAT_LOCAL);
		}

		$lastStatus = $this->lastStatus;
		if ($lastStatus !== null) {
			$lastStatus->setExportFormat(ACore::FORMAT_LOCAL);
		}

		return [
			'id' => (string)$this->getId(),
			'unread' => $this->isUnread(),
			'accounts' => $this->accounts,
			'last_status' => $lastStatus,
		];
	}
}
