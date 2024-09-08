<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use OCA\Social\Tools\Traits\TArrayTools;

class Status implements \JsonSerializable {
	use TArrayTools;

	private string $contentType = '';
	private bool $sensitive = false;
	private string $visibility = '';
	private string $spoilerText = '';
	private array $mediaIds = [];
	private int $inReplyToId = 0;
	private string $status = '';

	//"media_ids": [],

	public function __construct() {
	}


	/**
	 * @param string $contentType
	 *
	 * @return Status
	 */
	public function setContentType(string $contentType): self {
		$this->contentType = $contentType;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getContentType(): string {
		return $this->contentType;
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
		$this->setContentType($this->get('content_type', $data));
		$this->setSensitive($this->getBool('sensitive', $data));
		$this->setVisibility($this->get('visibility', $data));
		$this->setSpoilerText($this->get('spoiler_text', $data));
		$this->setMediaIds($this->getArray('media_ids', $data));
		$this->setInReplyToId($this->getInt('in_reply_to_id', $data));
		$this->setStatus($this->get('status', $data));

		return $this;
	}

	public function jsonSerialize(): array {
		return [
			'contentType' => $this->getContentType(),
			'sensitive' => $this->isSensitive(),
			'mediaIds' => $this->getMediaIds(),
			'visibility' => $this->getVisibility(),
			'spoilerText' => $this->getSpoilerText(),
			'status' => $this->getStatus()
		];
	}
}
