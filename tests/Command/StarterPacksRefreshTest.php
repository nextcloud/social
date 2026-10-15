<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\StarterPacksRefresh;
use OCA\Social\Service\CollectionPackService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class StarterPacksRefreshTest extends TestCase {
	public function testItReadsTheSourcesAndSaysWhatCameBack(): void {
		$service = $this->createMock(CollectionPackService::class);
		$service->method('sources')->willReturn(['Gargron@mastodon.social']);
		$service->expects($this->once())->method('refresh')
			->willReturn(['sources' => 1, 'packs' => 5, 'members' => 31, 'refused' => 2, 'failed' => 0]);
		$tester = new CommandTester(new StarterPacksRefresh($service));

		$this->assertSame(0, $tester->execute([]));

		$output = $tester->getDisplay();
		$this->assertStringContainsString('Sources: Gargron@mastodon.social', $output);
		$this->assertStringContainsString('5 starter pack(s) with 31 account(s) read from 1 source(s)', $output);
		$this->assertStringContainsString('2 account(s) left out', $output);
	}
}
