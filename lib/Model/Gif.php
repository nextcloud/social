<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;

/**
 * One picture in the instance's GIF library.
 */
class Gif implements JsonSerializable {
	private string $url = '';

	public function __construct(
		private string $slug,
		private string $filename,
		private string $mediaType,
		private string $title = '',
		private int $id = 0,
	) {
	}

	/**
	 * Whether a string may name a picture.
	 *
	 * The slug is a path segment of a public URL and the stem of a filename in
	 * appdata, so it is held to what is safe in both: lowercase letters,
	 * digits, `-` and `_`. That rules out `.` and `/` and therefore rules out
	 * traversal, which is the reason the rule is this narrow rather than
	 * "anything without a slash".
	 */
	public static function isSlug(string $slug): bool {
		return preg_match('/^[a-z0-9_-]{2,64}$/', $slug) === 1;
	}

	public function getId(): int {
		return $this->id;
	}

	public function getSlug(): string {
		return $this->slug;
	}

	public function getFilename(): string {
		return $this->filename;
	}

	public function getMediaType(): string {
		return $this->mediaType;
	}

	public function getTitle(): string {
		return $this->title;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function setUrl(string $url): self {
		$this->url = $url;

		return $this;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'slug' => $this->getSlug(),
			'title' => $this->getTitle(),
			'url' => $this->getUrl(),
			'media_type' => $this->getMediaType(),
		];
	}
}
