<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\FillInteractions;
use OCA\Social\Service\Interaction\InteractionService;
use OCA\Social\Service\RemoteFetchQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class FillInteractionsTest extends TestCase {
	public function testAnIncompleteReadReleasesItsThrottleForTheNextVisiblePoll(): void {
		$interactions = $this->createMock(InteractionService::class);
		$interactions->expects($this->once())->method('fill')->with('https://remote.example/notes/1', 'Like')->willReturn(true);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->once())->method('completeInteractionFill')->with('https://remote.example/notes/1', 'Like');
		$job = new FillInteractions($this->createStub(ITimeFactory::class), $interactions, $queue);

		(new \ReflectionMethod(FillInteractions::class, 'run'))->invoke($job, ['post' => 'https://remote.example/notes/1', 'type' => 'Like']);
	}

	public function testACompleteReadKeepsTheNormalThrottle(): void {
		$interactions = $this->createMock(InteractionService::class);
		$interactions->expects($this->once())->method('fill')->willReturn(false);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->never())->method('completeInteractionFill');
		$job = new FillInteractions($this->createStub(ITimeFactory::class), $interactions, $queue);

		(new \ReflectionMethod(FillInteractions::class, 'run'))->invoke($job, ['post' => 'https://remote.example/notes/1', 'type' => 'Like']);
	}
}
