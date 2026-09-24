<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\Fediverse;
use OCA\Social\Service\FediverseService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class FediverseTest extends TestCase {
	private FediverseService|MockObject $fediverseService;
	private CommandTester $tester;
	private string $csvPath;

	protected function setUp(): void {
		$this->fediverseService = $this->createMock(FediverseService::class);
		$this->tester = new CommandTester(new Fediverse($this->fediverseService));
		$csvPath = tempnam(sys_get_temp_dir(), 'social-fediverse-');
		if ($csvPath === false) {
			$this->fail('Could not create the temporary CSV fixture.');
		}
		$this->csvPath = $csvPath;
	}

	protected function tearDown(): void {
		if (isset($this->csvPath) && is_file($this->csvPath)) {
			unlink($this->csvPath);
		}
	}

	public function testImportsTheBadSpaceDomainColumnAndDeduplicatesIt(): void {
		file_put_contents($this->csvPath, "#domain,#severity,#public_comment\n"
			. "first.example,suspend,\"reason, with comma\"\n"
			. "FIRST.EXAMPLE.,suspend,duplicate\nsecond.example,silence,\"another, reason\"\n");
		$this->fediverseService->method('getAccessType')->willReturn('all_but');
		$this->fediverseService->expects($this->once())
			->method('addAddresses')
			->with(['first.example', 'second.example'])
			->willReturn(2);

		$this->assertSame(0, $this->tester->execute(['action' => 'import', 'address' => $this->csvPath]));
		$this->assertStringContainsString('Imported 2 domains; 0 were already listed.', $this->tester->getDisplay());
	}

	public function testAInvalidDomainAbortsBeforeAnyChange(): void {
		file_put_contents($this->csvPath, "#domain\nvalid.example\nhttps://invalid.example\n");
		$this->fediverseService->method('getAccessType')->willReturn('all_but');
		$this->fediverseService->expects($this->never())->method('addAddresses');

		$this->assertSame(1, $this->tester->execute(['action' => 'import', 'address' => $this->csvPath]));
		$this->assertStringContainsString('No domains were imported.', $this->tester->getDisplay());
	}

	public function testImportRefusesToChangeAnAllowList(): void {
		file_put_contents($this->csvPath, "blocked.example\n");
		$this->fediverseService->method('getAccessType')->willReturn('none_but');
		$this->fediverseService->expects($this->never())->method('addAddresses');

		$this->assertSame(1, $this->tester->execute(['action' => 'import', 'address' => $this->csvPath]));
		$this->assertStringContainsString('access mode was not changed', $this->tester->getDisplay());
	}

	public function testAnEmptyCsvDoesNotReplaceOrClearTheCurrentList(): void {
		file_put_contents($this->csvPath, "#domain,#severity\n\n");
		$this->fediverseService->method('getAccessType')->willReturn('all_but');
		$this->fediverseService->expects($this->never())->method('addAddresses');

		$this->assertSame(1, $this->tester->execute(['action' => 'import', 'address' => $this->csvPath]));
		$this->assertStringContainsString('did not contain any domains', $this->tester->getDisplay());
	}
}
