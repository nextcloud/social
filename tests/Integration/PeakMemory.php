<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration;

use PHPUnit\Framework\Assert;

/**
 * How much memory a block of work reached at its peak, above what was in use
 * when it began.
 *
 * Measured with `memory_get_peak_usage()` after `memory_reset_peak_usage()`,
 * so what the test process held before — the container, earlier tests — is
 * not counted, and what the block let go of before it returned still is:
 * a step that reads a whole table, walks it and drops it shows up at its
 * peak, which is the moment an upgrade runs out of memory.
 *
 * ```php
 * $small = PeakMemory::of(fn () => $step->run($output));     // over 1,000 rows
 * $large = PeakMemory::of(fn () => $step->run($output));     // over 5,000 rows
 * PeakMemory::assertFlat($small, $large, 'the backfill');
 * ```
 */
final class PeakMemory {
	/**
	 * What two runs may differ by and still be the same: a fifth of the
	 * smaller run, and never less than 512 KB.
	 *
	 * A step that pages holds one page at its peak however long the table is,
	 * so its two runs differ only by how full that page was and by what PHP's
	 * allocator does around it. Measured on SQLite, PostgreSQL and MySQL, the
	 * largest such difference here is 0.43 MB — the recipient-nid backfill,
	 * one page of 5,000 posts against five, where the previous page is still
	 * held while the next one is fetched — and most are under 10 KB. A step
	 * that reads everything holds every row: the key rotation over every local
	 * account went from 2.6 MB to 12.8 MB from 1,000 accounts to 5,000 before
	 * it was paged, and even the smallest row any of these steps reads (a nid
	 * and a hash, about 200 bytes fetched) puts 0.8 MB between a step that
	 * holds 1,000 of them and one that holds 5,000. The fraction keeps a step
	 * whose page is large from failing on the noise around it.
	 */
	public const FLOOR = 512 * 1024;
	public const FRACTION = 0.2;

	/** Bytes above the starting point that `$block` reached at its peak. */
	public static function of(callable $block): int {
		gc_collect_cycles();
		$before = memory_get_usage();
		memory_reset_peak_usage();
		$block();

		return max(0, memory_get_peak_usage() - $before);
	}

	/**
	 * Fails when the run over more rows reached a peak higher than the run
	 * over fewer by more than the tolerance above.
	 */
	public static function assertFlat(int $small, int $large, string $what): void {
		$allowed = max(self::FLOOR, (int)($small * self::FRACTION));
		Assert::assertLessThanOrEqual(
			$small + $allowed, $large,
			sprintf(
				'%s: its peak grew from %s to %s with five times the rows, more than the %s a paged step may differ by',
				$what, self::format($small), self::format($large), self::format($allowed)
			)
		);
	}

	public static function format(int $bytes): string {
		return sprintf('%.1f MB', $bytes / 1048576);
	}
}
