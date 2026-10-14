<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Atproto\Identity\KeySealService;
use OCA\Social\Command\AtprotoKeysReseal;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ social:atproto:keys:reseal`: counts the keys that do not open,
 * and re-seals them with a previous secret that comes from the
 * environment, never from the command line.
 */
#[AllowMockObjectsWithoutExpectations]
class AtprotoKeysResealTest extends TestCase {
	protected function tearDown(): void {
		putenv('OC_OLD_SECRET');
	}

	public function testWithoutTheSecretItCountsAndSaysWhatToDo(): void {
		$seals = $this->createMock(KeySealService::class);
		$seals->method('countSealed')->willReturn(4);
		$seals->expects($this->never())->method('reseal');
		$tester = new CommandTester(new AtprotoKeysReseal($seals));

		$this->assertSame(1, $tester->execute([]));
		$this->assertStringContainsString('4 keys cannot be opened with the current secret; run again with --old-secret-from-env', $tester->getDisplay());

		putenv('OC_OLD_SECRET');
		$this->assertSame(1, $tester->execute(['--old-secret-from-env' => true]));
		$this->assertStringContainsString('needs the previous secret in OC_OLD_SECRET', $tester->getDisplay());
	}

	public function testTheOldSecretComesFromTheEnvironment(): void {
		$seals = $this->createMock(KeySealService::class);
		$seals->expects($this->once())->method('reseal')->with('the old one')->willReturn(['resealed' => 5, 'failed' => 0]);
		putenv('OC_OLD_SECRET=the old one');
		$tester = new CommandTester(new AtprotoKeysReseal($seals));

		$this->assertSame(0, $tester->execute(['--old-secret-from-env' => true]));
		$this->assertStringContainsString('5 keys sealed again, 0 could not be opened', $tester->getDisplay());
		$this->assertStringNotContainsString('the old one', $tester->getDisplay());
	}

	public function testAKeyThatStaysSealedIsAFailure(): void {
		$seals = $this->createMock(KeySealService::class);
		$seals->method('reseal')->willReturn(['resealed' => 2, 'failed' => 1]);
		putenv('OC_OLD_SECRET=x');
		$tester = new CommandTester(new AtprotoKeysReseal($seals));

		$this->assertSame(1, $tester->execute(['--old-secret-from-env' => true]));
	}
}
