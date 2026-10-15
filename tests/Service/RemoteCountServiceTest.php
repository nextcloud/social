<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\Counts\CountService;
use OCA\Social\Service\RemoteCountService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The cron's pass over the remote posts nobody looked at: the ones due are
 * handed to the network each lives on, a round at a time, until the pass's
 * deadline.
 */
#[AllowMockObjectsWithoutExpectations]
class RemoteCountServiceTest extends TestCase {
	/** @return Note[] */
	private function posts(int $count): array {
		$posts = [];
		for ($i = 0; $i < $count; $i++) {
			$post = new Note();
			$post->setId('https://remote.example/notes/' . $i);
			$posts[] = $post;
		}

		return $posts;
	}

	public function testTheDuePostsAreHandedOverARoundAtATime(): void {
		$streamRequest = $this->createMock(StreamRequest::class);
		$streamRequest->expects($this->once())->method('getRemoteStreamsDueForCounts')->with($this->anything(), 30)->willReturn($this->posts(30));
		$counts = $this->createMock(CountService::class);
		$counts->expects($this->exactly(2))->method('refreshPosts')
			->willReturnCallback(static fn (array $posts): array => ['asked' => count($posts), 'answered' => 1]);

		$service = new RemoteCountService($streamRequest, $counts, $this->createStub(ITimeFactory::class), new NullLogger());

		$this->assertSame(['asked' => 30, 'answered' => 2], $service->refresh(false, 30));
	}

	public function testLocalPostsOnBlueskyOfTheLastWeekAreAskedAboutInTheSamePass(): void {
		$streamRequest = $this->createMock(StreamRequest::class);
		$streamRequest->method('getRemoteStreamsDueForCounts')->willReturn($this->posts(2));
		$streamRequest->expects($this->once())->method('getPublishedStreamsDueForCounts')
			->with(
				$this->anything(),
				$this->callback(static fn (\DateTime $since): bool => abs(time() - RemoteCountService::PUBLISHED_WINDOW - $since->getTimestamp()) < 60),
				30,
			)
			->willReturn([$this->local()]);
		$counts = $this->createMock(CountService::class);
		$counts->expects($this->once())->method('refreshPosts')
			->with($this->callback(static fn (array $posts): bool => count($posts) === 3 && $posts[2]->isLocal()))
			->willReturn(['asked' => 3, 'answered' => 3]);

		$service = new RemoteCountService($streamRequest, $counts, $this->createStub(ITimeFactory::class), new NullLogger());

		$this->assertSame(['asked' => 3, 'answered' => 3], $service->refresh(false, 30));
		$this->assertSame(7 * 86400, RemoteCountService::PUBLISHED_WINDOW);
	}

	private function local(): Note {
		$post = new Note();
		$post->setId('https://cloud.example/users/alice/statuses/1');
		$post->setLocal(true);

		return $post;
	}

	public function testAPassPastItsDeadlineLeavesTheRestForTheNext(): void {
		$streamRequest = $this->createMock(StreamRequest::class);
		$streamRequest->method('getRemoteStreamsDueForCounts')->willReturn($this->posts(RemoteCountService::PARALLEL + 1));
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnOnConsecutiveCalls(100, 200);
		$counts = $this->createMock(CountService::class);
		$counts->expects($this->once())->method('refreshPosts')->willReturn(['asked' => RemoteCountService::PARALLEL, 'answered' => 0]);

		$service = new RemoteCountService($streamRequest, $counts, $time, new NullLogger());

		$this->assertSame(['asked' => RemoteCountService::PARALLEL, 'answered' => 0], $service->refresh(true, 0, 150));
	}
}
