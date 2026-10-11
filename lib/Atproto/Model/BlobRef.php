<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Model;

use OCA\Social\Atproto\Protocol\Cid;

/**
 * A blob a repository holds: the stored document it is, by CID.
 */
final class BlobRef {
	/** what a blob of any other media type is stored and served as */
	public const OPAQUE_TYPE = 'application/octet-stream';
	/** the media types a blob is served as inline: pictures, videos and sounds, nothing a browser runs */
	public const INLINE_TYPES = [
		'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/heic', 'image/heif',
		'video/mp4', 'video/webm', 'video/quicktime',
		'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/opus', 'audio/wav', 'audio/x-wav', 'audio/flac', 'audio/aac',
	];

	public function __construct(
		public readonly string $did,
		public readonly Cid $cid,
		public readonly string $documentId,
		public readonly string $mime,
		public readonly int $size,
	) {
	}

	/**
	 * The media type a blob is stored and served as: one of INLINE_TYPES,
	 * else `application/octet-stream`.
	 */
	public static function servableType(string $mime): string {
		$mime = strtolower(trim(explode(';', $mime, 2)[0]));

		return in_array($mime, self::INLINE_TYPES, true) ? $mime : self::OPAQUE_TYPE;
	}

	/** the reference a record carries */
	public function toRecordValue(): array {
		return ['$type' => 'blob', 'ref' => $this->cid, 'mimeType' => $this->mime, 'size' => $this->size];
	}
}
