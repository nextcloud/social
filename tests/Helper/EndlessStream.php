<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

/**
 * A response body that never ends, as a stream.
 *
 * What a hostile or broken server can send: reading it whole never finishes,
 * so a reader that returns at all has bounded itself. `$read` counts the bytes
 * handed out, for the test that wants to see how many.
 */
class EndlessStream {
	public static int $read = 0;

	/** @var resource|null */
	public $context;

	/** @return resource an open handle on a body with no end */
	public static function open() {
		if (!in_array('endless', stream_get_wrappers(), true)) {
			stream_wrapper_register('endless', self::class);
		}
		self::$read = 0;

		$handle = fopen('endless://body', 'r');
		if ($handle === false) {
			throw new \RuntimeException('the endless stream did not open');
		}

		return $handle;
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool {
		return true;
	}

	public function stream_read(int $count): string {
		self::$read += $count;

		return str_repeat('a', $count);
	}

	public function stream_eof(): bool {
		return false;
	}

	public function stream_close(): void {
	}

	/** @return array<int|string, int> */
	public function stream_stat(): array {
		return [];
	}
}
