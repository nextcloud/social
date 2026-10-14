<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use InvalidArgumentException;
use OCA\Social\Atproto\Move\RotationKeys;
use PHPUnit\Framework\TestCase;

/**
 * The rotation keys of a later operation (§4.5): the head's, in their
 * order, only this server's key and the recovery key changed.
 */
class RotationKeysTest extends TestCase {
	private const OURS = 'did:key:zOurs';
	private const USER = 'did:key:zUser';
	private const RECOVERY = 'did:key:zRecovery';

	public function testTheHeadsKeysAreKeptInTheirOrderWithReplacementsInPlace(): void {
		$this->assertSame([self::USER, 'did:key:zNew', 'did:key:zOther'], RotationKeys::carry([self::USER, self::OURS, 'did:key:zOther', 42], [self::OURS => 'did:key:zNew'], 'did:key:zNew'));
		$this->assertSame([self::USER], RotationKeys::carry([self::USER, self::OURS], [self::OURS => ''], self::OURS), 'dropped for an empty replacement');
		$this->assertSame([self::OURS, self::USER], RotationKeys::carry(['did:key:zRetired', self::OURS, self::USER], ['did:key:zRetired' => self::OURS], self::OURS), 'no key twice');
	}

	public function testAnAddedKeyGoesBesideThisServersKey(): void {
		$this->assertSame([self::OURS, self::RECOVERY], RotationKeys::carry([self::OURS], [], self::OURS, self::RECOVERY), 'below it where this server is first');
		$this->assertSame([self::USER, self::RECOVERY, self::OURS], RotationKeys::carry([self::USER, self::OURS], [], self::OURS, self::RECOVERY), 'above it where the person ranks first');
		$this->assertSame([self::USER, self::RECOVERY], RotationKeys::carry([self::USER], [], self::OURS, self::RECOVERY), 'last where this server is not listed');
		$this->assertSame([self::RECOVERY, self::OURS], RotationKeys::carry([self::RECOVERY, self::OURS], [], self::OURS, self::RECOVERY), 'listed already');
	}

	public function testNoSixthKey(): void {
		$this->expectException(InvalidArgumentException::class);

		RotationKeys::carry(['did:key:z1', 'did:key:z2', 'did:key:z3', 'did:key:z4', self::OURS], [], self::OURS, self::RECOVERY);
	}

	/** What an operation changed against the head it was built on, carried onto the newer one. */
	public function testAChangeIsCarriedOntoANewerHead(): void {
		$this->assertSame([self::USER, 'did:key:zNew'], RotationKeys::rebase([self::OURS], ['did:key:zNew'], [self::USER, self::OURS], 'did:key:zNew'), 'a replacement');
		$this->assertSame([self::USER, self::RECOVERY, self::OURS], RotationKeys::rebase([self::OURS], [self::OURS, self::RECOVERY], [self::USER, self::OURS], self::OURS), 'an addition');
		$this->assertSame([self::USER, self::OURS], RotationKeys::rebase([self::OURS], [self::OURS], [self::USER, self::OURS], self::OURS), 'no change');
	}
}
