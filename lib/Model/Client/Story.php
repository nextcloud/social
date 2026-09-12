<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Tools\IQueryRow;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * One picture that stops existing after a day.
 *
 * The expiry is the feature, not a detail of it: it is the reason people put
 * things here that they would not put on a timeline. Everything else -- the
 * caption, the hold time, who has seen it -- hangs off that.
 *
 * A story is not a post. It has no `social_stream` row, no ActivityPub identity
 * and no recipients, and it is not federated. Pixelfed's are local too, and
 * giving them an identity would mean answering for what a peer did with its
 * copy after the day was up.
 */
class Story implements IQueryRow, JsonSerializable {
	use TArrayTools;

	/** How long a story lives. */
	public const LIFETIME = 24 * 3600;

	/** How long a client holds on one picture, in seconds. */
	public const DEFAULT_DURATION = 5;
	public const MIN_DURATION = 3;
	public const MAX_DURATION = 30;

	/** How many an account may have live at once. */
	public const MAX_PER_ACTOR = 40;

	public const CAPTION_MAX = 500;

	private int $id = 0;
	private string $ownerId = '';
	private string $documentId = '';
	private string $caption = '';
	private int $duration = self::DEFAULT_DURATION;
	private int $creation = 0;
	private int $expiresAt = 0;
	private bool $seen = false;
	private int $viewCount = 0;

	/** Filled in when the story is read for a client. */
	private ?MediaAttachment $media = null;
	private ?Person $author = null;

	public function getId(): int {
		return $this->id;
	}

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getOwnerId(): string {
		return $this->ownerId;
	}

	public function setOwnerId(string $ownerId): self {
		$this->ownerId = $ownerId;

		return $this;
	}

	public function getDocumentId(): string {
		return $this->documentId;
	}

	public function setDocumentId(string $documentId): self {
		$this->documentId = $documentId;

		return $this;
	}

	public function getCaption(): string {
		return $this->caption;
	}

	public function setCaption(string $caption): self {
		$this->caption = mb_substr($caption, 0, self::CAPTION_MAX);

		return $this;
	}

	public function getDuration(): int {
		return $this->duration;
	}

	/** Clamped: a story that holds for an hour is not a story. */
	public function setDuration(int $duration): self {
		$this->duration = max(self::MIN_DURATION, min(self::MAX_DURATION, $duration));

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getExpiresAt(): int {
		return $this->expiresAt;
	}

	public function setExpiresAt(int $expiresAt): self {
		$this->expiresAt = $expiresAt;

		return $this;
	}

	public function hasExpired(?int $now = null): bool {
		return $this->expiresAt <= ($now ?? time());
	}

	public function isSeen(): bool {
		return $this->seen;
	}

	public function setSeen(bool $seen): self {
		$this->seen = $seen;

		return $this;
	}

	public function getViewCount(): int {
		return $this->viewCount;
	}

	public function setViewCount(int $viewCount): self {
		$this->viewCount = $viewCount;

		return $this;
	}

	public function getMedia(): ?MediaAttachment {
		return $this->media;
	}

	public function setMedia(?MediaAttachment $media): self {
		$this->media = $media;

		return $this;
	}

	public function getAuthor(): ?Person {
		return $this->author;
	}

	public function setAuthor(?Person $author): self {
		$this->author = $author;

		return $this;
	}

	#[\Override]
	public function importFromDatabase(array $data): void {
		$this->setId($this->getInt('id', $data));
		$this->setOwnerId($this->get('actor_id', $data));
		$this->setDocumentId($this->get('document_id', $data));
		$this->setCaption($this->get('caption', $data));
		$this->setDuration($this->getInt('duration', $data, self::DEFAULT_DURATION));

		$creation = $this->get('creation', $data);
		$this->setCreation(($creation === '') ? 0 : (int)strtotime($creation));
		$expires = $this->get('expires_at', $data);
		$this->setExpiresAt(($expires === '') ? 0 : (int)strtotime($expires));
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->getId(),
			'account_id' => $this->getOwnerId(),
			'caption' => $this->getCaption(),
			'duration' => $this->getDuration(),
			'created_at' => date('c', $this->getCreation()),
			'expires_at' => date('c', $this->getExpiresAt()),
			'seen' => $this->isSeen(),
			'view_count' => $this->getViewCount(),
			'media' => $this->getMedia(),
			'account' => $this->getAuthor(),
		];
	}
}
