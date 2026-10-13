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
 * A body made in pieces as it goes out: each piece is produced — read from
 * the database, encoded — only when the one before it has been written,
 * so what is held at any moment is one piece, never the whole. What
 * `com.atproto.sync.getRepo` answers with: a repository's CAR, which can be
 * larger than the memory a request has.
 *
 * @template-extends Response<Http::STATUS_*, array<string, mixed>>
 */
class StreamedPiecesResponse extends Response implements ICallbackResponse {
	/**
	 * @param iterable<string> $pieces
	 * @param Http::STATUS_* $status
	 * @param array<string, string> $headers
	 */
	public function __construct(
		private iterable $pieces,
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
		// output buffering would collect the pieces into the whole this
		// response exists not to hold
		while (ob_get_level() > 0) {
			ob_end_flush();
		}

		foreach ($this->pieces as $piece) {
			$output->setOutput($piece);
			if (connection_aborted() !== 0) {
				break;
			}
			flush();
		}
	}
}
