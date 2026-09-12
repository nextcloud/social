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
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Mastodon's StatusEdit entity: one version a status has been through.
 *
 * A revision is a snapshot of the three fields an edit may change — the text,
 * the content warning and the sensitivity flag — and of when that version came
 * into being. It is deliberately not a Status: it carries no id, no counts and
 * no interaction state, because a client renders it as a diff against the
 * status it already has rather than as a post of its own.
 *
 * `account` is the status author, the same on every revision of one status,
 * and is attached when the history is read rather than stored: an author's
 * display name and avatar are theirs now, not as they were at the time, and
 * copying them into every revision row would freeze a stale one into the
 * history.
 *
 * `poll`, `media_attachments` and `emojis` are always `null`/`[]`. Nothing
 * here can edit any of the three — `PostService::editPost()` takes content,
 * spoiler and sensitivity and nothing else — so there is no version of them to
 * record. The keys are emitted because a client that declares them
 * non-optional cannot decode the entity without them.
 */
class StatusRevision implements JsonSerializable {
	use TArrayTools;

	private int $id = 0;
	private string $streamId = '';
	private string $content = '';
	private string $spoilerText = '';
	private bool $sensitive = false;
	private string $published = '';
	private ?Person $account = null;

	/**
	 * The version a status is in right now.
	 *
	 * `published` is the edit stamp when there is one and the creation stamp
	 * when there is not, which is what makes the first revision of a status
	 * carry the time it was posted rather than the time it was first changed.
	 */
	public static function fromStream(Stream $stream): self {
		$updated = $stream->getUpdated();

		return (new self())
			->setStreamId($stream->getId())
			->setContent($stream->getContent())
			->setSpoilerText($stream->getSpoilerText())
			->setSensitive($stream->isSensitive())
			->setPublished(($updated === '') ? $stream->getPublished() : $updated);
	}

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setStreamId(string $streamId): self {
		$this->streamId = $streamId;

		return $this;
	}

	public function getStreamId(): string {
		return $this->streamId;
	}

	public function setContent(string $content): self {
		$this->content = $content;

		return $this;
	}

	public function getContent(): string {
		return $this->content;
	}

	public function setSpoilerText(string $spoilerText): self {
		$this->spoilerText = $spoilerText;

		return $this;
	}

	public function getSpoilerText(): string {
		return $this->spoilerText;
	}

	public function setSensitive(bool $sensitive): self {
		$this->sensitive = $sensitive;

		return $this;
	}

	public function isSensitive(): bool {
		return $this->sensitive;
	}

	public function setPublished(string $published): self {
		$this->published = $published;

		return $this;
	}

	public function getPublished(): string {
		return $this->published;
	}

	public function setAccount(?Person $account): self {
		$this->account = $account;

		return $this;
	}

	public function getAccount(): ?Person {
		return $this->account;
	}

	/** @param array<string, mixed> $data a row of `social_stream_rev` */
	public function importFromDatabase(array $data): self {
		$this->setId($this->getInt('id', $data))
			->setStreamId($this->get('stream_id_prim', $data))
			->setContent($this->get('content', $data))
			->setSpoilerText($this->get('spoiler_text', $data))
			->setSensitive($this->getInt('sensitive', $data) === 1)
			->setPublished($this->get('published', $data));

		return $this;
	}

	/**
	 * `account` is exported in the client format, not the ActivityPub one: a
	 * StatusEdit is only ever read by a Mastodon client, and the federated
	 * shape of an actor is not something any of them can decode.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		$account = $this->getAccount();
		if ($account !== null) {
			$account->setExportFormat(ACore::FORMAT_LOCAL);
		}

		return [
			'content' => $this->getContent(),
			'spoiler_text' => $this->getSpoilerText(),
			'sensitive' => $this->isSensitive(),
			'created_at' => $this->getPublished(),
			'account' => $account,
			'poll' => null,
			'media_attachments' => [],
			'emojis' => [],
		];
	}
}
