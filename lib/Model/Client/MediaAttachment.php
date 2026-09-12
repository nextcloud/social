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
use OCP\IURLGenerator;
use OCP\Server;

class MediaAttachment implements JsonSerializable {
	use TArrayTools;

	/**
	 * The tail of a link this instance serves media on: the cached document's
	 * uuid, optionally carrying the extension the mime type implies.
	 */
	private const MEDIA_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(\.[a-z0-9]+)?$/i';

	private string $id = '';
	private string $type = '';
	private string $mediaType = '';
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

	/**
	 * The full media type (`image/jpeg`), as opposed to `type`, which is the
	 * half of it Mastodon's client entity carries (`image`).
	 *
	 * Kept because the ActivityPub `Document` this becomes on the way out has
	 * to state it: a peer that does not sniff the file has nothing else to go
	 * on, and this was an empty string on every attachment this app federated.
	 */
	public function getMediaType(): string {
		return $this->mediaType;
	}

	public function setMediaType(string $mediaType): self {
		$this->mediaType = $mediaType;

		return $this;
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

	#[\Override]
	public function jsonSerialize(): array {
		if ($this->getExportFormat() === ACore::FORMAT_LOCAL) {
			return $this->asLocal();
		}

		return $this->asDocument();
	}

	/**
	 * Mastodon's MediaAttachment entity.
	 *
	 * Every key is always present. This used to be wrapped in `array_filter()`
	 * with no callback, which drops every *falsy* value rather than every empty
	 * one: an attachment with no alt text lost `description`, an image with no
	 * preview lost `preview_url`, a local upload lost `remote_url`, and the
	 * first attachment ever cached (id `"0"`) lost its `id`. A client that
	 * declares those keys non-optional — which Mastodon's own documentation
	 * permits, since Mastodon always sends them — fails to decode the
	 * attachment, and with it the whole status it hangs off.
	 */
	public function asLocal(): array {
		$meta = $this->getMeta()?->jsonSerialize();
		$preview = $this->onThisInstance($this->getPreviewUrl());
		$remote = $this->getRemoteUrl();

		return [
			'id' => $this->getId(),
			'type' => $this->getType(),
			'url' => $this->onThisInstance($this->getUrl()),
			'preview_url' => ($preview === null || $preview === '') ? null : $preview,
			'remote_url' => ($remote === null || $remote === '') ? null : $remote,
			// `meta` is an object on the wire, never a list: an empty
			// AttachmentMeta json-encodes as `[]`, which a client decoding a
			// dictionary rejects
			'meta' => ($meta === null || $meta === []) ? null : (object)$meta,
			'description' => ($this->getDescription() === '') ? null : $this->getDescription(),
			'blurhash' => ($this->getBlurHash() === '') ? null : $this->getBlurHash(),
		];
	}

	/**
	 * A media link pointed at the address this instance answers on now.
	 *
	 * The stored link was absolute when the attachment was written, so rows
	 * written before the instance moved — or written by cron under a different
	 * `overwrite.cli.url` than a web request would have used — point at a host
	 * that no longer serves them. Only the last segment, the document uuid,
	 * stays meaningful, so the link is rebuilt from it. Anything whose tail is
	 * not one of our uuids is somebody else's URL and is handed back untouched.
	 */
	private function onThisInstance(?string $stored): ?string {
		if ($stored === null || $stored === '') {
			return $stored;
		}

		$uuid = substr($stored, (int)strrpos($stored, '/') + 1);
		if (preg_match(self::MEDIA_UUID, $uuid) !== 1) {
			return $stored;
		}

		return Server::get(IURLGenerator::class)
			->linkToRouteAbsolute('social.Api.mediaOpen', ['uuid' => $uuid]);
	}

	/**
	 * quick implementation of converting MediaAttachment to Document. Can be improved.
	 *
	 * @return array
	 */
	public function asDocument(): array {
		$original = $this->getMeta()?->getOriginal();

		return
			[
				'type' => Document::TYPE,
				'mediaType' => $this->getMediaType(),
				'url' => $this->getUrl(),
				// the wire carries the alt text as `name`
				'name' => ($this->getDescription() === '') ? null : $this->getDescription(),
				'blurhash' => $this->getBlurHash(),
				'width' => ($original === null) ? 0 : $original->getWidth() ?? 0,
				'height' => ($original === null) ? 0 : $original->getHeight() ?? 0
			];
	}
}
