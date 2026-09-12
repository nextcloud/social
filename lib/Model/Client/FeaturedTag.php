<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Mastodon's FeaturedTag entity: a hashtag an account has pinned to its own
 * profile.
 *
 * `statuses_count` and `last_status_at` are not stored on the row. They are
 * counted from `social_stream_tag` when the entity is built and set here, so
 * a featured tag cannot report a number that a post created, deleted or edited
 * since would have changed.
 *
 * `url` is set by the service for the same reason it is in
 * `HashtagService::tagEntity()`: a model has no URL generator, and a link to
 * this instance's own tag timeline is not something it can derive from the tag
 * alone.
 *
 * `owner` is not part of the entity a client sees. It is carried so that the
 * one row a request names can be checked against the account that asked before
 * anything is done with it — see FeaturedTagsRequest, where the check is a SQL
 * predicate rather than a comparison made after the row was read.
 */
class FeaturedTag implements JsonSerializable {
	use TArrayTools;

	private int $id = 0;
	private string $ownerId = '';
	private string $hashtag = '';
	private string $url = '';
	private int $statusesCount = 0;
	private string $lastStatusAt = '';

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setOwnerId(string $ownerId): self {
		$this->ownerId = $ownerId;

		return $this;
	}

	public function getOwnerId(): string {
		return $this->ownerId;
	}

	public function setHashtag(string $hashtag): self {
		$this->hashtag = $hashtag;

		return $this;
	}

	public function getHashtag(): string {
		return $this->hashtag;
	}

	public function setUrl(string $url): self {
		$this->url = $url;

		return $this;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function setStatusesCount(int $statusesCount): self {
		$this->statusesCount = $statusesCount;

		return $this;
	}

	public function getStatusesCount(): int {
		return $this->statusesCount;
	}

	public function setLastStatusAt(string $lastStatusAt): self {
		$this->lastStatusAt = $lastStatusAt;

		return $this;
	}

	public function getLastStatusAt(): string {
		return $this->lastStatusAt;
	}

	/** @param array<string, mixed> $data a row of `social_featured_tag` */
	public function importFromDatabase(array $data): self {
		$this->setId($this->getInt('id', $data))
			->setOwnerId($this->get('actor_id', $data))
			->setHashtag($this->get('hashtag', $data));

		return $this;
	}

	/**
	 * `last_status_at` is a date and not a timestamp — Mastodon sends
	 * `2022-08-29` here — and is null rather than an empty string when the
	 * account has not posted with the tag: a client renders the field as a
	 * date and an empty string is not one.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->getId(),
			'name' => $this->getHashtag(),
			'url' => $this->getUrl(),
			'statuses_count' => $this->getStatusesCount(),
			'last_status_at' => ($this->lastStatusAt === '') ? null : $this->lastStatusAt,
		];
	}
}
