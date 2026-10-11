<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Fuzz;

/**
 * One way a target failed, with the smallest input found that fails so.
 */
final class Finding {
	public function __construct(
		public readonly string $target,
		public readonly string $label,
		public readonly string $failure,
		public readonly mixed $input,
		public readonly int $seed,
	) {
	}

	/** What a report shows: the failure, the run's seed, and the input in a form that can be pasted back. */
	public function describe(): string {
		$input = is_string($this->input)
			? 'base64:' . base64_encode(substr($this->input, 0, 4096)) . ((strlen($this->input) > 4096) ? ' (first 4096 of ' . strlen($this->input) . ' bytes)' : '')
			: 'json:' . substr((string)json_encode($this->input, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES), 0, 4096);

		return sprintf("%s, %s of seed %d: %s\n    %s", $this->target, $this->label, $this->seed, $this->failure, $input);
	}
}
