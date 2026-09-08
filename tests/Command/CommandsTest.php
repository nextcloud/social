<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use PHPUnit\Framework\TestCase;

/**
 * The occ commands (lib/Command/*) extend the server-internal
 * OC\Core\Command\Base, itself a Symfony Console command. Neither the server
 * nor symfony/console is part of this standalone harness, so the command
 * classes cannot even be autoloaded here (a fatal, not an exception), and
 * Symfony's CommandTester is unavailable. This placeholder documents the gap.
 */
class CommandsTest extends TestCase {
	public function testCommandsNeedTheServerAndSymfonyConsole(): void {
		if (class_exists(\OC\Core\Command\Base::class) && class_exists(\Symfony\Component\Console\Tester\CommandTester::class)) {
			$this->fail('The command dependencies are now available: write the tests/Command/*Test.php suite.');
		}

		$this->markTestSkipped('OC\Core\Command\Base and symfony/console are not available in the unit-test harness.');
	}
}
