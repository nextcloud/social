<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools;

use InvalidArgumentException;
use OCA\Social\Tools\Nid;
use PHPUnit\Framework\TestCase;

class NidTest extends TestCase {
	public function testNormalizePreservesIdentifiersLargerThanPhpInt(): void {
		$this->assertSame('1700000000000000123', Nid::normalize('01700000000000000123'));
		$this->assertSame('1700000000000000123', Nid::normalize('1700000000000000123'));
	}

	public function testStoredIdentifiersStayIntegersOnlyWhenTheRuntimeCanHoldThem(): void {
		$this->assertSame(PHP_INT_MAX, Nid::fromStorage((string)PHP_INT_MAX));
		$aboveMax = Nid::increment((string)PHP_INT_MAX);
		$this->assertSame($aboveMax, Nid::fromStorage($aboveMax));
	}

	public function testCompareOrdersLargeDecimalIdentifiersExactly(): void {
		$this->assertSame(-1, Nid::compare('1700000000000000123', '1700000000000000124'));
		$this->assertSame(-1, Nid::compare('999999999999999999', '1000000000000000000'));
		$this->assertSame(0, Nid::compare('00123', '123'));
	}

	public function testIncrementAndDecrementStayExactAbovePhpIntMax(): void {
		$this->assertSame('9223372036854775808', Nid::increment('9223372036854775807'));
		$this->assertSame('999999999999999999', Nid::decrement('1000000000000000000'));
		$this->assertSame('0', Nid::decrement('1'));
	}

	public function testComposeLargeNidWithoutNativeIntegerMultiplication(): void {
		$this->assertSame(
			'1700000000000000123',
			Nid::fromPublishedTime(1700000000, 123, 1000000000)
		);
	}

	public function testThePublishedTimeIsReadBackOffANid(): void {
		$this->assertSame(1700000000, Nid::publishedTimeOf('1700000000000000123', 1000000000));
		$this->assertSame(1700000000, Nid::publishedTimeOf(Nid::fromPublishedTime(1700000000, 999999999, 1000000000), 1000000000));
	}

	public function testANidTooShortToCarryATimeHasNone(): void {
		$this->assertSame(0, Nid::publishedTimeOf('123456789', 1000000000));
		$this->assertSame(0, Nid::publishedTimeOf('0', 1000000000));
		// thirteen digits of seconds is not a date, and not an int on 32 bits
		$this->assertSame(0, Nid::publishedTimeOf('1234567890123000000000', 1000000000));
	}

	public function testNormalizeRejectsNonDecimalValues(): void {
		$this->expectException(InvalidArgumentException::class);
		Nid::normalize('12.3');
	}
}
