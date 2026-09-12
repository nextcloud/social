<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Traits\TArrayTools;

class Status implements \JsonSerializable {
	use TArrayTools;

	private bool $sensitive = false;
	private string $visibility = '';
	private string $spoilerText = '';
	private array $mediaIds = [];
	private ?array $poll = null;
	private int $inReplyToId = 0;
	/**
	 * The post this one quotes, as the client named it: the numeric status id
	 * a Mastodon client sends, or an ActivityPub URI. Kept as a string for
	 * exactly that reason — `(int)'https://…'` is 0, which is a quote of
	 * whatever post happens to have that id.
	 */
	private string $quotedId = '';
	private string $status = '';
	/** BCP 47 as the client sent it, normalised; empty for "whatever the poster's default is" */
	private string $language = '';

	//"media_ids": [],

	public function __construct() {
	}

	/**
	 * @param bool $sensitive
	 *
	 * @return Status
	 */
	public function setSensitive(bool $sensitive): self {
		$this->sensitive = $sensitive;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isSensitive(): bool {
		return $this->sensitive;
	}

	/**
	 * @param string $visibility
	 *
	 * @return Status
	 */
	public function setVisibility(string $visibility): self {
		$this->visibility = $visibility;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getVisibility(): string {
		return $this->visibility;
	}

	/**
	 * @param string $spoilerText
	 *
	 * @return Status
	 */
	public function setSpoilerText(string $spoilerText): self {
		$this->spoilerText = $spoilerText;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getSpoilerText(): string {
		return $this->spoilerText;
	}

	public function setMediaIds(array $mediaIds): self {
		$this->mediaIds = array_map(function (string $id): int {
			return (int)$id;
		}, $mediaIds);

		return $this;
	}

	public function getMediaIds(): array {
		return $this->mediaIds;
	}

	public function setInReplyToId(int $inReplyToId): self {
		$this->inReplyToId = $inReplyToId;

		return $this;
	}

	public function getInReplyToId(): int {
		return $this->inReplyToId;
	}

	public function setQuotedId(string $quotedId): self {
		$this->quotedId = trim($quotedId);

		return $this;
	}

	public function getQuotedId(): string {
		return $this->quotedId;
	}

	/**
	 * Validated loosely rather than against a list: an unusable value is
	 * dropped so the poster's default language applies, see
	 * `Stream::normalizeLanguage()`.
	 */
	public function setLanguage(string $language): self {
		$this->language = Stream::normalizeLanguage($language);

		return $this;
	}

	public function getLanguage(): string {
		return $this->language;
	}

	/**
	 * @param string $status
	 *
	 * @return Status
	 */
	public function setStatus(string $status): self {
		$this->status = $status;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getStatus(): string {
		return $this->status;
	}

	public function import(array $data): self {
		$this->setSensitive($this->getBool('sensitive', $data));
		$this->setVisibility($this->get('visibility', $data));
		$this->setSpoilerText($this->get('spoiler_text', $data));
		$this->setMediaIds($this->getArray('media_ids', $data));
		$this->setInReplyToId($this->getInt('in_reply_to_id', $data));
		// `quote_id` is what a Mastodon 4.5 client sends to quote a post; a
		// client that sends something that is not a scalar quotes nothing
		$quotedId = $data['quote_id'] ?? '';
		$this->setQuotedId(is_scalar($quotedId) ? (string)$quotedId : '');
		$this->setStatus($this->get('status', $data));
		$this->setLanguage($this->get('language', $data));
		$poll = $this->getArray('poll', $data);
		$this->setPoll($poll === [] ? null : $poll);

		return $this;
	}

	public function setPoll(?array $poll): self {
		$this->poll = $poll;

		return $this;
	}

	public function getPoll(): ?array {
		return $this->poll;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'sensitive' => $this->isSensitive(),
			'mediaIds' => $this->getMediaIds(),
			'visibility' => $this->getVisibility(),
			'spoilerText' => $this->getSpoilerText(),
			'language' => $this->getLanguage(),
			'status' => $this->getStatus()
		];
	}
}
