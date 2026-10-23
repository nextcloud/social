<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Discovery;

/** Bounded operational counters from one discovery source call. */
final readonly class PostSourceStats {
	public function __construct(
		public int $requests,
		public int $failedRequests,
		public int $rateLimitedRequests,
		public int $postsSeen,
		public int $postsStored,
		public int $durationMs,
	) {
	}

	public static function empty(): self {
		return new self(0, 0, 0, 0, 0, 0);
	}
}
