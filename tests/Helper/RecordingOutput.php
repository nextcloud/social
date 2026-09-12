<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

use Symfony\Component\Console\Output\NullOutput;

/**
 * A console output that keeps what was written to it, so a test can assert on
 * what an export or an import told the operator.
 *
 * It extends `NullOutput` for the eleven methods of `OutputInterface` nothing
 * here cares about — verbosity, decoration, the formatter — and records what is
 * written verbatim, without running it through a formatter that would eat the
 * `<info>` tags a test may want to see.
 */
class RecordingOutput extends NullOutput {
	/** @var string[] */
	private array $lines = [];

	#[\Override]
	public function writeln(string|iterable $messages, int $options = self::OUTPUT_NORMAL): void {
		foreach (is_iterable($messages) ? $messages : [$messages] as $message) {
			$this->lines[] = (string)$message;
		}
	}

	/** @return string[] */
	public function lines(): array {
		return $this->lines;
	}

	public function text(): string {
		return implode("\n", $this->lines);
	}
}
