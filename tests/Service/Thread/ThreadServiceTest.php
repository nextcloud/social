<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Thread;

use OCA\Social\Atproto\Reader\BlueskyThreadSource;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\Thread\ActivityPubThreadSource;
use OCA\Social\Service\Thread\ThreadService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ThreadServiceTest extends TestCase {
	public function testBlueskyConversationCanBeReadInlineWhenOpened(): void {
		$post = (new Note())->setId('at://did:plc:alice/app.bsky.feed.post/1');
		$streams = $this->createStub(StreamRequest::class);
		$bluesky = $this->createMock(BlueskyThreadSource::class);
		$bluesky->expects($this->once())->method('supports')->with($post)->willReturn(true);
		$bluesky->expects($this->once())->method('fill')->with($post, ThreadService::BUDGET)->willReturn(2);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(BlueskyThreadSource::class)->willReturn($bluesky);

		$this->assertTrue((new ThreadService($streams, new NullLogger(), $container))->fillBluesky($post));
	}

	/** A post written here and published to Bluesky is asked of both. */
	public function testEveryNetworkThePostIsOnIsAskedWithWhatIsLeftOfTheBudget(): void {
		$post = (new Note())->setId('https://social.test/@alice/1');
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturn($post);
		$fediverse = $this->createMock(ActivityPubThreadSource::class);
		$fediverse->method('supports')->willReturn(true);
		$fediverse->expects($this->once())->method('fill')->with($post, ThreadService::BUDGET)->willReturn(100);
		$bluesky = $this->createMock(BlueskyThreadSource::class);
		$bluesky->method('supports')->willReturn(true);
		$bluesky->expects($this->once())->method('fill')->with($post, ThreadService::BUDGET - 100)->willReturn(7);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[ActivityPubThreadSource::class, $fediverse], [BlueskyThreadSource::class, $bluesky]]);

		$this->assertSame(107, (new ThreadService($streams, new NullLogger(), $container))->fill($post->getId()));
	}
}
