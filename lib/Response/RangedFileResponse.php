<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Response;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;
use OCP\Files\SimpleFS\ISimpleFile;

/**
 * A stored file, served a byte range at a time.
 *
 * `FileDisplayResponse` sends the whole thing and says nothing about ranges,
 * which is fine for a picture and wrong for anything with a timeline in it: a
 * browser cannot seek a video it can only receive from the beginning, so the
 * scrub bar does nothing, and asking for the duration alone costs the whole
 * file. On a page of twenty videos that is twenty full downloads before
 * anybody has pressed play.
 *
 * So this answers `Accept-Ranges: bytes`, and when the reader asks for part of
 * a file it sends that part with a `206` and a `Content-Range`. The rules are
 * RFC 9110's: a range past the end is unsatisfiable and answered `416` with the
 * length, a suffix range (`bytes=-500`) is the last N bytes, an open range
 * (`bytes=500-`) runs to the end, and anything malformed is ignored rather than
 * refused — the whole file is a valid answer to a range request nobody could
 * parse.
 *
 * Multipart ranges (`bytes=0-99,200-299`) are deliberately not implemented.
 * No media element asks for one, and the answer to a range request this does
 * not handle is the whole file, which is always correct.
 *
 * @template-extends Response<Http::STATUS_*, array<string, mixed>>
 */
class RangedFileResponse extends Response implements ICallbackResponse {
	/** What one read takes, and therefore the ceiling on memory here. */
	private const CHUNK = 262144;

	private int $size;
	private int $offset = 0;
	private int $length;

	public function __construct(
		private ISimpleFile $file,
		string $contentType,
		string $range = '',
	) {
		parent::__construct();

		$this->size = (int)$this->file->getSize();
		$this->length = $this->size;

		$this->addHeader('Content-Type', $contentType);
		// the offer has to be made before a browser will make use of it
		$this->addHeader('Accept-Ranges', 'bytes');

		$asked = $this->parseRange($range);
		if ($asked === null) {
			$this->addHeader('Content-Length', (string)$this->size);

			return;
		}

		if ($asked === false) {
			// RFC 9110 §15.5.17: say how long the file actually is, so the
			// client can ask again for something that exists
			$this->setStatus(Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE);
			$this->addHeader('Content-Range', 'bytes */' . $this->size);
			$this->length = 0;

			return;
		}

		[$this->offset, $this->length] = $asked;

		$this->setStatus(Http::STATUS_PARTIAL_CONTENT);
		$this->addHeader('Content-Length', (string)$this->length);
		$this->addHeader(
			'Content-Range',
			'bytes ' . $this->offset . '-' . ($this->offset + $this->length - 1) . '/' . $this->size
		);
	}

	/**
	 * What the reader asked for.
	 *
	 * @return array{0: int, 1: int}|false|null offset and length, false when
	 *                                          unsatisfiable, null for "all of it"
	 */
	private function parseRange(string $range): array|false|null {
		if ($range === '' || $this->size <= 0) {
			return null;
		}

		if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) !== 1) {
			return null;
		}

		[, $first, $last] = $m;

		if ($first === '' && $last === '') {
			return null;
		}

		if ($first === '') {
			// a suffix range: the last N bytes, and asking for more of them
			// than exist is the whole file rather than an error
			$length = min((int)$last, $this->size);

			return ($length <= 0) ? false : [$this->size - $length, $length];
		}

		$offset = (int)$first;
		if ($offset >= $this->size) {
			return false;
		}

		$end = ($last === '') ? $this->size - 1 : min((int)$last, $this->size - 1);
		if ($end < $offset) {
			return false;
		}

		return [$offset, $end - $offset + 1];
	}

	#[\Override]
	public function callback(IOutput $output): void {
		if ($this->length <= 0) {
			return;
		}

		$stream = $this->file->read();
		if (!is_resource($stream)) {
			return;
		}

		// object storage hands back a stream that cannot be seeked, so the
		// offset is reached by reading up to it rather than jumping
		if ($this->offset > 0 && fseek($stream, $this->offset) !== 0) {
			$this->skip($stream, $this->offset);
		}

		$remaining = $this->length;
		while ($remaining > 0 && !feof($stream)) {
			$chunk = fread($stream, min(self::CHUNK, $remaining));
			if ($chunk === false || $chunk === '') {
				break;
			}

			$output->setOutput($chunk);
			$remaining -= strlen($chunk);

			if (connection_aborted() !== 0) {
				break;
			}
		}

		fclose($stream);
	}

	/** @param resource $stream */
	private function skip($stream, int $bytes): void {
		while ($bytes > 0 && !feof($stream)) {
			$chunk = fread($stream, min(self::CHUNK, $bytes));
			if ($chunk === false || $chunk === '') {
				return;
			}

			$bytes -= strlen($chunk);
		}
	}
}
