<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Exceptions;

use Exception;

/**
 * A peer answered, and not with a 2xx; the code is its HTTP status.
 */
class RequestContentException extends Exception {
	/** How long the peer asked to be left alone (`Retry-After`), in seconds; 0 where it did not. */
	private int $retryAfter = 0;

	public function getRetryAfter(): int {
		return $this->retryAfter;
	}

	public function setRetryAfter(int $seconds): static {
		$this->retryAfter = max(0, $seconds);

		return $this;
	}
}
