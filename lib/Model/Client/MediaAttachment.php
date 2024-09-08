<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Tools\Traits\TArrayTools;

class MediaAttachment implements JsonSerializable {
	use TArrayTools;

	private string $id = '';
	private string $type = '';
	private ?string $url = null;
	private string $previewUrl = '';
	private ?string $remoteUrl = null;
	private string $textUrl = '';
	private ?AttachmentMeta $meta = null;
	private string $description = '';
	private string $blurHash = '';
	private int $exportFormat = ACore::FORMAT_LOCAL;

	public function setId(string $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): string {
		return $this->id;
	}

	public function setType(string $type): self {
		$this->type = $type;

		return $this;
	}

	public function getType(): string {
		return $this->type;
	}

	public function setUrl(string $url): self {
		$this->url = $url;

		return $this;
	}

	public function getUrl(): ?string {
		return $this->url;
	}

	public function setPreviewUrl(string $previewUrl): self {
		$this->previewUrl = $previewUrl;

		return $this;
	}

	public function getPreviewUrl(): string {
		return $this->previewUrl;
	}

	public function setRemoteUrl(string $remoteUrl): self {
		$this->remoteUrl = $remoteUrl;

		return $this;
	}

	public function getRemoteUrl(): ?string {
		return $this->remoteUrl;
	}

	public function setTextUrl(string $textUrl): self {
		$this->textUrl = $textUrl;

		return $this;
	}

	public function getTextUrl(): string {
		return $this->textUrl;
	}

	public function setMeta(AttachmentMeta $meta): self {
		$this->meta = $meta;

		return $this;
	}

	public function getMeta(): ?AttachmentMeta {
		return $this->meta;
	}

	public function setDescription(string $description): self {
		$this->description = $description;

		return $this;
	}

	public function getDescription(): string {
		return $this->description;
	}

	public function setBlurHash(string $blurHash): self {
		$this->blurHash = $blurHash;

		return $this;
	}

	public function getBlurHash(): string {
		return $this->blurHash;
	}


	public function setExportFormat(int $exportFormat): self {
		$this->exportFormat = $exportFormat;

		return $this;
	}

	public function getExportFormat(): int {
		return $this->exportFormat;
	}


	public function import(array $data): self {
		$this->setId($this->get('id', $data));
		$this->setType($this->get('type', $data));
		$this->setUrl($this->get('url', $data));
		$this->setPreviewUrl($this->get('preview_url', $data));
		$this->setRemoteUrl($this->get('remote_url', $data));
		$this->setDescription($this->get('description', $data));
		$this->setBlurHash($this->get('blurhash', $data));

		$meta = new AttachmentMeta();
		$meta->import($this->getArray('meta', $data));
		$this->setMeta($meta);

		return $this;
	}

	public function jsonSerialize(): array {
		if ($this->getExportFormat() === ACore::FORMAT_LOCAL) {
			return $this->asLocal();
		}

		return $this->asDocument();
	}

	public function asLocal(): array {
		return array_filter(
			[
				'id' => $this->getId(),
				'type' => $this->getType(),
				'url' => $this->getUrl(),
				'preview_url' => $this->getPreviewUrl(),
				'remote_url' => $this->getRemoteUrl(),
				'meta' => $this->getMeta(),
				'description' => $this->getDescription(),
				"blurhash" => $this->getBlurHash()
			]
		);
	}

	/**
	 * quick implementation of converting MediaAttachment to Document. Can be improved.
	 *
	 * @return array
	 */
	public function asDocument(): array {
		$original = $this->getMeta()->getOriginal();

		return
			[
				'type' => Document::TYPE,
				'mediaType' => '',
				'url' => $this->getUrl(),
				'name' => null,
				'blurhash' => $this->getBlurHash(),
				'width' => ($original === null) ? 0 : $original->getWidth() ?? 0,
				'height' => ($original === null) ? 0 : $original->getHeight() ?? 0
			];
	}
}
