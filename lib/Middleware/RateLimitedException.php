<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Middleware;

use Exception;

/**
 * A caller that has spent its budget, carrying what the answer has to say:
 * how big the budget was and when the next one starts.
 */
class RateLimitedException extends Exception {
	public function __construct(
		private int $limit,
		private int $period,
		private int $resetAt,
	) {
		parent::__construct('rate limited');
	}

	public function getLimit(): int {
		return $this->limit;
	}

	public function getPeriod(): int {
		return $this->period;
	}

	public function getResetAt(): int {
		return $this->resetAt;
	}
}
