<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\StoriesRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\Story;
use OCA\Social\Service\StoryService;
use OCA\Social\Tests\Helper\FrozenClock;
use OCA\Social\Tests\Integration\Helper\FreezesTheClock;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A story walked through its day on a frozen clock, against the real table:
 * every read that shows stories still shows it on its last second, none does
 * on the second its day is up, and the hourly sweep takes it on that second
 * and not before. The day is counted in seconds from when it was posted, so a
 * leap day, a month end or a daylight-saving night changes nothing.
 *
 * The clock stands in the past: the sweep takes every story that has ended by
 * then, and on an instance with stories of its own a clock in the future would
 * take theirs too.
 */
class StoryExpiryTest extends TestCase {
	use FreezesTheClock;

	private const ALICE = 'https://itest.example/users/story-expiry-alice';

	protected function setUp(): void {
		parent::setUp();
		Server::get(StoriesRequest::class)->deleteRelatedId(self::ALICE);
	}

	protected function tearDown(): void {
		Server::get(StoriesRequest::class)->deleteRelatedId(self::ALICE);
		$this->thawClock();
		parent::tearDown();
	}

	/** @return array<string, array{string}> */
	public static function postedAt(): array {
		return [
			'a month ago' => [''],
			'on the eve of a leap day' => ['2024-02-28T18:00:00Z'],
			'on a leap day' => ['2024-02-29T18:00:00Z'],
			'on the last day of October' => ['2025-10-31T23:30:00Z'],
			'on New Year\'s Eve' => ['2025-12-31T12:00:00Z'],
			'before the European autumn change' => ['2025-10-25T23:00:00Z'],
			'before the US spring change' => ['2025-03-08T12:00:00Z'],
		];
	}

	#[DataProvider('postedAt')]
	public function testAStoryIsShownForItsDayAndSweptOnTheSecondItEnds(string $moment): void {
		$clock = $this->freezeClock(($moment === '') ? new FrozenClock(time() - 30 * 86400) : FrozenClock::at($moment));
		$posted = $clock->getTime();
		$stories = $this->clocked(StoriesRequest::class);
		$story = $stories->save((new Story())
			->setOwnerId(self::ALICE)
			->setDocumentId('https://itest.example/media/' . bin2hex(random_bytes(4)))
			->setCaption('a day of it')
			->setDuration(Story::DEFAULT_DURATION)
			->setSourceId('https://itest.example/stories/' . bin2hex(random_bytes(4)))
			->setLocal(true));
		$this->assertSame($posted + Story::LIFETIME, $story->getExpiresAt());

		$clock->set($posted + Story::LIFETIME - 1);
		$this->assertShown(true, $story);
		$this->clocked(StoryService::class)->purgeExpired();
		$this->assertShown(true, $story);

		$clock->set($posted + Story::LIFETIME);
		$this->assertShown(false, $story);
		$this->clocked(StoryService::class)->purgeExpired();
		$this->assertNotContains($story->getId(), $this->ids($this->clocked(StoriesRequest::class)->getExpired()), 'the sweep on the second it ends takes it');
	}

	/** Each read that shows stories, against the same instant. */
	private function assertShown(bool $shown, Story $story): void {
		$stories = $this->clocked(StoriesRequest::class);
		$this->assertSame($shown, in_array($story->getId(), $this->ids($stories->getLiveByActor(self::ALICE)), true), 'the profile');
		$this->assertSame($shown, in_array($story->getId(), $this->ids($stories->getLiveByActors([self::ALICE])), true), 'the bar');
		$this->assertSame($shown ? 1 : 0, $stories->countLiveByActor(self::ALICE), 'the count');
		$this->assertSame(!$shown, in_array($story->getId(), $this->ids($stories->getExpired()), true), 'what the sweep would take');
		try {
			$stories->getLiveById($story->getId());
			$this->assertTrue($shown, 'the story was opened after its day');
		} catch (ItemNotFoundException $e) {
			$this->assertFalse($shown, 'the story could not be opened within its day');
		}
	}

	/**
	 * @param Story[] $stories
	 * @return int[]
	 */
	private function ids(array $stories): array {
		return array_map(static fn (Story $story): int => $story->getId(), $stories);
	}
}
