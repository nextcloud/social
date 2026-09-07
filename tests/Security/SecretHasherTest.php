<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Security;

use OCA\Social\Security\SecretHasher;
use PHPUnit\Framework\TestCase;

class SecretHasherTest extends TestCase {
	private SecretHasher $hasher;

	protected function setUp(): void {
		$this->hasher = new SecretHasher();
	}

	public function testHashIsDeterministicAndPrefixed(): void {
		$this->assertSame('sha256:' . hash('sha256', 'tok'), $this->hasher->hash('tok'));
		$this->assertSame($this->hasher->hash('tok'), $this->hasher->hash('tok'));
		$this->assertSame('', $this->hasher->hash(''));
	}

	public function testMatchesAHashedValue(): void {
		$stored = $this->hasher->hash('s3cret');

		$this->assertTrue($this->hasher->matches($stored, 's3cret'));
		$this->assertFalse($this->hasher->matches($stored, 'other'));
		$this->assertFalse($this->hasher->matches($stored, ''));
	}

	public function testMatchesALegacyPlaintextValue(): void {
		$this->assertTrue($this->hasher->matches('s3cret', 's3cret'));
		$this->assertFalse($this->hasher->matches('s3cret', 'other'));
		$this->assertFalse($this->hasher->matches('', 's3cret'));
	}

	public function testForLookupCoversHashedAndLegacyRows(): void {
		$this->assertSame(
			[$this->hasher->hash('tok'), 'tok'],
			$this->hasher->forLookup('tok')
		);
		$this->assertSame([], $this->hasher->forLookup(''));
	}

	public function testIsHashed(): void {
		$this->assertTrue($this->hasher->isHashed($this->hasher->hash('x')));
		$this->assertFalse($this->hasher->isHashed('x'));
		$this->assertFalse($this->hasher->isHashed(''));
	}
}
