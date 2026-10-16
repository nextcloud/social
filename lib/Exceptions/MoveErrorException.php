<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use Throwable;

/**
 * A move refused or stopped, an import or export of the Migration page
 * refused, or a change to the account's own Bluesky account refused (its
 * handle, an app password), with a stable code beside the English sentence:
 * the page explains the code in the person's language, the sentence is for
 * logs and older clients. The codes are listed in Atproto-Compatibility
 * §13.5 and, for the Migration routes, in API.md (Migration error codes).
 */
class MoveErrorException extends InvalidResourceException {
	public function __construct(
		string $message,
		public readonly string $errorCode,
		?Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	/** What a route answers for it. */
	public function toArray(): array {
		return ['error' => $this->getMessage(), 'code' => $this->errorCode];
	}
}
