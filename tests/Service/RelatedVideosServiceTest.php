<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\RelatedVideosService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * What a video's page offers next, and from where.
 *
 * The timelines and the filters are the real ones' business and are stood in
 * for here; what is held is which timelines are asked, how narrowly, under
 * which filter context, and the order the answers are put together in.
 */
#[AllowMockObjectsWithoutExpectations]
class RelatedVideosServiceTest extends TestCase {
	private const AUTHOR = 'https://tube.example/video-channels/films';

	/** @var list<array{probe: string, account: string, tag: string, video: bool, limit: int, format: int}> */
	private array $asked = [];

	/** @var list<string> */
	private array $contexts = [];

	/** @var array<string, list<array<string, mixed>>> what each timeline answers, by probe (and tag) */
	private array $answers = [];

	private RelatedVideosService $service;

	protected function setUp(): void {
		$streams = $this->createMock(StreamService::class);
		$streams->method('getTimeline')->willReturnCallback(function (ProbeOptions $options): array {
			$this->asked[] = [
				'probe' => $options->getProbe(),
				'account' => $options->getAccountId(),
				'tag' => $options->getArgument(),
				'video' => $options->isOnlyVideo(),
				'limit' => $options->getLimit(),
				'format' => $options->getFormat(),
			];
			$key = $options->getProbe() . ($options->getArgument() === '' ? '' : ':' . $options->getArgument());

			return $this->answers[$key] ?? [];
		});

		$filters = $this->createMock(FilterService::class);
		$filters->method('apply')->willReturnCallback(function (array $items, string $context): array {
			$this->contexts[] = $context;

			return $items;
		});

		$this->service = new RelatedVideosService($streams, $filters);
	}

	/** @param list<string> $tags */
	private function video(array $tags = [], string $category = ''): Note {
		$note = new Note();
		$note->setId('https://tube.example/videos/watch/1');
		$note->setNid(100);
		$note->setAttributedTo(self::AUTHOR);
		$note->setHashtags($tags);
		if ($category !== '') {
			$note->setVideoMeta(['title' => 'Boats', 'category' => $category]);
		}

		return $note;
	}

	/** @return array<string, mixed> a served video status */
	private static function served(string $id, ?string $category = null): array {
		return ['id' => $id, 'video_card' => ['title' => 'Video ' . $id, 'category' => $category]];
	}

	/** @param list<array<string, mixed>> $statuses */
	private static function ids(array $statuses): array {
		return array_map(static fn (array $status): string => $status['id'], $statuses);
	}

	public function testItAsksTheChannelTheTagsAndTheCategoryEachNarrowedToVideos(): void {
		$this->service->related($this->video(['Boats', 'sea', 'wood', 'rope'], 'Travel'), new Person(), 6);

		$this->assertSame([
			['probe' => ProbeOptions::ACCOUNT, 'account' => self::AUTHOR, 'tag' => '', 'video' => true, 'limit' => 7, 'format' => ACore::FORMAT_LOCAL],
			['probe' => ProbeOptions::HASHTAG, 'account' => '', 'tag' => 'Boats', 'video' => true, 'limit' => 7, 'format' => ACore::FORMAT_LOCAL],
			['probe' => ProbeOptions::HASHTAG, 'account' => '', 'tag' => 'sea', 'video' => true, 'limit' => 7, 'format' => ACore::FORMAT_LOCAL],
			['probe' => ProbeOptions::HASHTAG, 'account' => '', 'tag' => 'wood', 'video' => true, 'limit' => 7, 'format' => ACore::FORMAT_LOCAL],
			['probe' => ProbeOptions::PUBLIC, 'account' => '', 'tag' => '', 'video' => true, 'limit' => 40, 'format' => ACore::FORMAT_LOCAL],
		], $this->asked, 'three hashtags at most, and the category among the newest forty public videos');
		$this->assertSame(
			[Filter::CONTEXT_ACCOUNT, Filter::CONTEXT_PUBLIC, Filter::CONTEXT_PUBLIC, Filter::CONTEXT_PUBLIC, Filter::CONTEXT_PUBLIC],
			$this->contexts,
			'each answer goes through the reader\'s filters as its own timeline does'
		);
	}

	public function testAPostWithNeitherTagsNorACategoryAsksOnlyTheChannel(): void {
		$this->service->related($this->video(), null);

		$this->assertSame([ProbeOptions::ACCOUNT], array_column($this->asked, 'probe'));
	}

	public function testATagWrittenTwiceIsAskedOnce(): void {
		$this->service->related($this->video(['#Boats', 'boats', ' ']), null);

		$this->assertSame(['', 'Boats'], array_column($this->asked, 'tag'));
	}

	public function testTheChannelLeadsWithHalfAndWhatIsLeftOfItComesLast(): void {
		$this->answers = [
			ProbeOptions::ACCOUNT => [self::served('100'), self::served('c1'), self::served('c2'), self::served('c3'), self::served('c4')],
			ProbeOptions::HASHTAG . ':boats' => [self::served('t1'), self::served('c1'), self::served('t2')],
			ProbeOptions::PUBLIC => [self::served('p1', 'Music'), self::served('p2', 'Travel'), self::served('t2', 'Travel')],
		];

		$related = $this->service->related($this->video(['boats'], 'Travel'), null, 6);

		$this->assertSame(
			['c1', 'c2', 't1', 't2', 'p2', 'c3'], self::ids($related),
			'the post itself is not offered, nothing twice, another category not at all, and the list stops at six'
		);
	}

	public function testWhatCarriesNoVideoCardIsNotOffered(): void {
		$this->answers = [ProbeOptions::ACCOUNT => [['id' => 'text'], self::served('c1'), ['id' => 'odd', 'video_card' => null]]];

		$this->assertSame(['c1'], self::ids($this->service->related($this->video(), null)));
	}

	public function testTheListIsBounded(): void {
		$this->service->related($this->video(), null, 500);
		$this->service->related($this->video(), null, -3);

		$this->assertSame([RelatedVideosService::MAX_LIMIT + 1, 2], array_column($this->asked, 'limit'));
	}
}
