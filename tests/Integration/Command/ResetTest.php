<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Command;

use OCA\Social\Command\Reset;
use OCA\Social\Service\ConfigService;
use OCP\Server;

/**
 * `social:reset` empties every Social table, so these tests deliberately never
 * let it get that far: they exercise the option parsing and both refusal paths,
 * which is where the bugs were.
 *
 * The documented remedy for a cloud address that no longer matches the server
 * was `occ social:reset --uri=<address>` — printed by `social:check:install`
 * and by the README — and the command declared no such option, so Symfony
 * rejected it outright. That is what testUriIsADeclaredOption pins down: with
 * the option missing, binding the input throws before the command runs.
 */
class ResetTest extends CommandTestCase {
	public function testUriIsADeclaredOption(): void {
		$tester = $this->tester(Reset::class);

		// no --force, non-interactive: refused before anything is deleted, but
		// only *after* the option has been accepted
		$code = $this->runNonInteractive($tester, ['--uri' => 'https://cloud.example.org']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('--force', $tester->getDisplay());
	}

	public function testTheDocumentedOptionsExist(): void {
		$definition = $this->command(Reset::class)->getDefinition();

		$this->assertTrue($definition->hasOption('uri'), 'documented in README.md and by social:check:install');
		$this->assertTrue($definition->hasOption('force'));
		$this->assertTrue($definition->hasOption('uninstall'));
	}

	public function testNonInteractiveWithoutForceDeletesNothing(): void {
		$configService = Server::get(ConfigService::class);
		$before = $configService->getCloudUrl();
		$tester = $this->tester(Reset::class);

		// The prompts used to answer themselves with their default (false) and
		// the command exited 0 having done nothing, which a script could not
		// tell from success.
		$code = $this->runNonInteractive($tester, []);

		$this->assertSame(1, $code, 'a refusal has to be an error, not a silent no-op');
		$this->assertSame($before, $configService->getCloudUrl());
	}

	public function testUninstallIsAlsoRefusedNonInteractively(): void {
		$tester = $this->tester(Reset::class);

		$code = $this->runNonInteractive($tester, ['--uninstall' => true]);

		$this->assertSame(1, $code);
	}

	public function testDecliningTheFirstPromptChangesNothing(): void {
		$configService = Server::get(ConfigService::class);
		$before = $configService->getCloudUrl();
		$tester = $this->tester(Reset::class);

		$code = $this->runInteractive($tester, [], ['n']);

		$this->assertSame(0, $code, 'a deliberate "no" is not an error');
		$this->assertStringContainsString('cancelled', $tester->getDisplay());
		$this->assertSame($before, $configService->getCloudUrl());
	}

	public function testDecliningTheSecondPromptChangesNothing(): void {
		$configService = Server::get(ConfigService::class);
		$before = $configService->getCloudUrl();
		$tester = $this->tester(Reset::class);

		$code = $this->runInteractive($tester, [], ['y', 'n']);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('cancelled', $tester->getDisplay());
		$this->assertSame($before, $configService->getCloudUrl());
	}
}
