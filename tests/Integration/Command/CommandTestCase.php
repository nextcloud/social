<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Command;

use OCP\Server;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Base for the occ command tests.
 *
 * The unit suite can read a command's definition — they extend the app's own
 * SocialCommand, which extends Symfony's Command — but it cannot run one:
 * that needs services, a database and a question helper. This suite boots a
 * real server and resolves every command from the container exactly as occ
 * resolves it, so a constructor these tests cannot satisfy is a constructor
 * occ cannot satisfy either.
 */
abstract class CommandTestCase extends TestCase {
	/**
	 * The command as occ would hand it to a user: built by the container and
	 * attached to an application, which is what provides the question helper
	 * and what rejects an option the command never declared.
	 *
	 * @param class-string $commandClass
	 */
	protected function command(string $commandClass): Command {
		$command = Server::get($commandClass);
		$this->assertInstanceOf(Command::class, $command);

		$application = new Application();
		$application->add($command);

		return $application->find($command->getName());
	}

	/**
	 * @param class-string $commandClass
	 */
	protected function tester(string $commandClass): CommandTester {
		return new CommandTester($this->command($commandClass));
	}

	/**
	 * Runs a command the way `occ --no-interaction` does.
	 *
	 * @param array<string, mixed> $input
	 */
	protected function runNonInteractive(CommandTester $tester, array $input): int {
		return $tester->execute($input, ['interactive' => false]);
	}

	/**
	 * Runs a command with the given answers queued for its prompts.
	 *
	 * @param array<string, mixed> $input
	 * @param list<string> $answers
	 */
	protected function runInteractive(CommandTester $tester, array $input, array $answers): int {
		$tester->setInputs($answers);

		return $tester->execute($input, ['interactive' => true]);
	}
}
