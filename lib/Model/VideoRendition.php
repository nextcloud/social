<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;

/**
 * One rung of a video's ladder: the same video, smaller.
 *
 * A rendition is one file — a fragmented MP4 written by ffmpeg with
 * `-hls_flags single_file` — and one playlist that addresses the segments
 * inside it as byte ranges. The playlist is kept as text with the media file's
 * name replaced by `{uri}`, because the URI it has to carry is a route on this
 * server: it is not known when the file is encoded, and it changes if the
 * instance moves.
 */
class VideoRendition implements JsonSerializable {
	/** What the media filename becomes in the stored playlist. */
	public const URI_PLACEHOLDER = '{uri}';

	private int $id = 0;
	private int $docNid = 0;
	private int $height = 0;
	private int $bandwidth = 0;
	private int $size = 0;
	private string $localCopy = '';
	private string $playlist = '';

	public function getId(): int {
		return $this->id;
	}

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getDocNid(): int {
		return $this->docNid;
	}

	public function setDocNid(int $docNid): self {
		$this->docNid = $docNid;

		return $this;
	}

	public function getHeight(): int {
		return $this->height;
	}

	public function setHeight(int $height): self {
		$this->height = $height;

		return $this;
	}

	public function getBandwidth(): int {
		return $this->bandwidth;
	}

	public function setBandwidth(int $bandwidth): self {
		$this->bandwidth = $bandwidth;

		return $this;
	}

	public function getSize(): int {
		return $this->size;
	}

	public function setSize(int $size): self {
		$this->size = $size;

		return $this;
	}

	public function getLocalCopy(): string {
		return $this->localCopy;
	}

	public function setLocalCopy(string $localCopy): self {
		$this->localCopy = $localCopy;

		return $this;
	}

	public function getPlaylist(): string {
		return $this->playlist;
	}

	public function setPlaylist(string $playlist): self {
		$this->playlist = $playlist;

		return $this;
	}

	/**
	 * The stored playlist with the media URI put back in.
	 *
	 * The one place the placeholder is resolved, so a playlist is never
	 * written to a client with a filename from the encoder in it.
	 */
	public function playlistFor(string $uri): string {
		return str_replace(self::URI_PLACEHOLDER, $uri, $this->playlist);
	}

	/**
	 * What the master playlist says about this rung.
	 *
	 * `RESOLUTION` is not given: the width is not stored (scale=-2 derives it
	 * from the source's aspect ratio) and an attribute that has to be right is
	 * better absent than guessed. `BANDWIDTH` is the one a player actually
	 * chooses on.
	 */
	public function masterEntry(string $uri): string {
		return '#EXT-X-STREAM-INF:BANDWIDTH=' . $this->bandwidth
			. ',NAME="' . $this->height . 'p"' . "\n" . $uri;
	}

	/**
	 * @return array<string, int|string>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'height' => $this->height,
			'bandwidth' => $this->bandwidth,
			'size' => $this->size,
		];
	}
}
