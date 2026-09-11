<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * A console output that keeps what was written to it, so a test can assert on
 * what an export or an import told the operator.
 */
class RecordingOutput implements OutputInterface {
	/** @var string[] */
	private array $lines = [];

	/**
	 * @param string|iterable $messages
	 */
	#[\Override]
	public function writeln($messages, int $options = 0) {
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
