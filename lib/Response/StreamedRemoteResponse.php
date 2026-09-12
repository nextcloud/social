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

/**
 * Bytes from another server, passed on as they arrive.
 *
 * Not `StreamResponse`, which takes a path and calls `readfile()` on it: there
 * is no file here and there is deliberately never going to be one. The body is
 * an open socket to the instance that holds the video, and what this does is
 * copy it to the client in fixed-size pieces, so a two-hour talk costs the
 * chunk size in memory rather than its own length.
 *
 * The reason it exists at all is that a `<video>` cannot be pointed at the
 * origin directly: the page's content security policy says `media-src 'self'`,
 * and widening it would also mean every reader who presses play announcing
 * themselves to a server they have never heard of. Proxying keeps both
 * promises, and costs this instance the bandwidth.
 *
 * @template-extends Response<Http::STATUS_*, array<string, mixed>>
 */
class StreamedRemoteResponse extends Response implements ICallbackResponse {
	/** What one `fread` takes, and therefore the ceiling on memory here. */
	private const CHUNK = 262144;

	/**
	 * @param resource $stream
	 * @param Http::STATUS_* $status
	 * @param array<string, string> $headers
	 */
	public function __construct(
		private $stream,
		int $status = Http::STATUS_OK,
		array $headers = [],
	) {
		parent::__construct();

		$this->setStatus($status);
		foreach ($headers as $name => $value) {
			$this->addHeader($name, $value);
		}
	}

	#[\Override]
	public function callback(IOutput $output): void {
		// Whatever the server was buffering for us is in the way: with output
		// buffering on, the whole video would be assembled in memory before a
		// byte of it reached anybody, which is the one thing this class exists
		// to avoid.
		while (ob_get_level() > 0) {
			ob_end_flush();
		}

		$stream = $this->stream;
		while (!feof($stream)) {
			$chunk = fread($stream, self::CHUNK);
			if ($chunk === false) {
				break;
			}

			$output->setOutput($chunk);

			// the reader closed the tab, or skipped ahead: there is nobody to
			// send the rest of the file to, and the origin should stop sending
			// it to us
			if (connection_aborted() !== 0) {
				break;
			}

			flush();
		}

		fclose($stream);
	}
}
