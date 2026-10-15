<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The hashtag page decided in two steps: a window of the tag's newest posts,
 * then the visibility filters over that window by key, refilled from below
 * when the filters empty it, and the one-join query where the windows run
 * out before the page fills. The SQL of each step is exercised by the
 * integration suite (QueryPlanTest, TimelineSeedTest).
 */
#[AllowMockObjectsWithoutExpectations]
class HashtagWindowTest extends TestCase {
	/** @var list<array{maxId: int, wanted: int}> the windows asked for */
	private array $windowsAsked = [];
	/** @var list<array<int|string>|null> what the filter query was handed as `$among` */
	private array $filtered = [];

	/**
	 * @param callable(int): list<int> $window the window below a max id (0: the newest)
	 * @param callable(list<int>|null): list<int> $filter the ids of a window that may be read
	 */
	private function request(callable $window, callable $filter): StreamRequest {
		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['hashtagTimelineNids', 'hashtagWindowNids', 'streamsByNids'])
			->getMock();
		$request->method('hashtagWindowNids')->willReturnCallback(function (ProbeOptions $options, int $wanted) use ($window): array {
			$this->windowsAsked[] = ['maxId' => $options->getMaxId(), 'wanted' => $wanted];

			return $window($options->getMaxId());
		});
		$request->method('hashtagTimelineNids')->willReturnCallback(function (ProbeOptions $options, ?array $among = null) use ($filter): array {
			$this->filtered[] = $among;

			return $filter($among);
		});
		$request->method('streamsByNids')->willReturnCallback(static fn (array $nids): array => array_map(static function ($nid): Stream {
			$note = new Note();
			$note->setNid((int)$nid);

			return $note;
		}, $nids));
		$viewer = new Person();
		$viewer->setId('https://cloud.example/users/alice');
		$request->setViewer($viewer);

		return $request;
	}

	/** @return list<int> */
	private static function nids(array $timeline): array {
		return array_map(static fn (Stream $post): int => $post->getNid(), $timeline);
	}

	private static function page(): ProbeOptions {
		return (new ProbeOptions())->setProbe(ProbeOptions::HASHTAG)->setArgument('nextcloud')->setLimit(20);
	}

	public function testThePageIsChosenFromTheWindowByKey(): void {
		$request = $this->request(
			static fn (int $maxId): array => range(130, 101),
			static fn (?array $among): array => array_values(array_filter((array)$among, static fn (int $nid): bool => $nid % 2 === 0)),
		);

		$this->assertSame(range(130, 102, 2), self::nids($request->getTimeline(self::page())));
		$this->assertSame([range(130, 101)], $this->filtered, 'the filters are applied to the window, and to nothing else');
		$this->assertSame([['maxId' => 0, 'wanted' => 60]], $this->windowsAsked, 'three times the page');
	}

	public function testAWindowTheFiltersThinOutIsRefilledFromBelowIt(): void {
		$request = $this->request(
			// a full window, then the tag's last thirty posts
			static fn (int $maxId): array => ($maxId === 0) ? range(1000, 941) : range(940, 911),
			// one in ten of each window may be read
			static fn (?array $among): array => array_values(array_filter((array)$among, static fn (int $nid): bool => $nid % 10 === 0)),
		);

		$timeline = self::nids($request->getTimeline(self::page()));

		$this->assertSame([1000, 990, 980, 970, 960, 950, 940, 930, 920], $timeline);
		$this->assertSame([0, 941], array_column($this->windowsAsked, 'maxId'), 'the next window starts below the smallest id of the last');
		$this->assertNotContains(null, $this->filtered, 'the windows answered: the join was not asked');
	}

	public function testWindowsThatRunOutBeforeThePageFillsLeaveItToTheJoin(): void {
		$request = $this->request(
			// every window full, and none of it may be read: a tag whose
			// posts the viewer mostly may not see
			static fn (int $maxId): array => range(($maxId ?: 100000) - 1, ($maxId ?: 100000) - 60),
			static fn (?array $among): array => ($among === null) ? [5, 4, 3] : [],
		);

		$this->assertSame([5, 4, 3], self::nids($request->getTimeline(self::page())));
		$this->assertSame(null, end($this->filtered), 'the last read is the join, over the whole tag');
	}

	public function testATagWithNoPostIsAnEmptyPageAndNoRead(): void {
		$request = $this->request(static fn (int $maxId): array => [], static fn (?array $among): array => []);

		$this->assertSame([], $request->getTimeline(self::page()));
		$this->assertSame([], $this->filtered);
	}
}
