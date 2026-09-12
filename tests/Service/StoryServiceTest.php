<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StoriesRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\Client\Story;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\StoryService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StoryServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example.org/users/alice';
	private const BOB = 'https://cloud.example.org/users/bob';

	private StoriesRequest|MockObject $storiesRequest;
	private DocumentService|MockObject $documentService;
	private FollowService|MockObject $followService;
	private StoryService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->storiesRequest = $this->createMock(StoriesRequest::class);
		$this->documentService = $this->createMock(DocumentService::class);
		$this->followService = $this->createMock(FollowService::class);
		$this->service = new StoryService(
			$this->storiesRequest,
			$this->documentService,
			$this->followService,
			$this->createMock(CacheActorService::class),
			$this->createMock(IURLGenerator::class)
		);
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id);
		$person->setPreferredUsername(basename($id));

		return $person;
	}

	private function story(string $owner, int $id = 1): Story {
		return (new Story())->setId($id)->setOwnerId($owner)
			->setExpiresAt(time() + Story::LIFETIME);
	}

	public function testPostingSomebodyElsesUploadIsRefused(): void {
		// the account filter is part of the lookup, so a foreign id finds nothing
		$this->documentService->method('getMediaFromArray')->willReturn([]);

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessageMatches('/unknown media/');

		$this->service->add($this->person(self::ALICE), 99, '', 5);
	}

	public function testAnAccountCannotKeepUnlimitedLiveStories(): void {
		$this->documentService->method('getMediaFromArray')->willReturn([new Document()]);
		$this->storiesRequest->method('countLiveByActor')->willReturn(Story::MAX_PER_ACTOR);

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessageMatches('/already has/');

		$this->service->add($this->person(self::ALICE), 99, '', 5);
	}

	public function testDeletingSomebodyElsesStoryIsANotFoundAndDeletesNothing(): void {
		$this->storiesRequest->method('getLiveById')->willReturn($this->story(self::BOB));
		$this->storiesRequest->expects($this->never())->method('delete');

		$this->expectException(ItemNotFoundException::class);

		$this->service->delete($this->person(self::ALICE), 1);
	}

	/**
	 * Whether an account has a story up is itself only told to its followers,
	 * so a stranger gets "there are none" rather than "not allowed".
	 */
	public function testAStrangerIsToldThereAreNoneRatherThanBeingRefused(): void {
		$this->followService->method('getLinksBetweenPersons')
			->willReturn(['follower' => false, 'following' => false]);

		$this->expectException(ItemNotFoundException::class);
		$this->expectExceptionMessageMatches('/no stories/');

		$this->service->forAccount($this->person(self::BOB), $this->person(self::ALICE));
	}

	public function testAFollowerSeesThem(): void {
		$this->followService->method('getLinksBetweenPersons')
			->willReturn(['follower' => false, 'following' => true]);
		$this->storiesRequest->method('getLiveByActor')->willReturn([]);

		$this->assertSame([], $this->service->forAccount($this->person(self::BOB), $this->person(self::ALICE)));
	}

	public function testAnOwnerSeesTheirOwnWithoutFollowingThemselves(): void {
		$this->followService->expects($this->never())->method('getLinksBetweenPersons');
		$this->storiesRequest->method('getLiveByActor')->willReturn([]);

		$this->service->forAccount($this->person(self::ALICE), $this->person(self::ALICE));
	}

	public function testMarkingAStrangersStorySeenIsRefused(): void {
		$this->storiesRequest->method('getLiveById')->willReturn($this->story(self::BOB));
		$this->followService->method('getLinksBetweenPersons')
			->willReturn(['follower' => false, 'following' => false]);
		$this->storiesRequest->expects($this->never())->method('markSeen');

		$this->expectException(ItemNotFoundException::class);

		$this->service->markSeen($this->person(self::ALICE), 1);
	}

	/** Only the poster is told how many people watched. */
	public function testTheViewCountIsFilledInOnlyForTheOwner(): void {
		$this->storiesRequest->method('seenAmong')->willReturn([]);
		$this->storiesRequest->method('countViews')->willReturn(17);
		$this->storiesRequest->method('getLiveByActor')->willReturn([$this->story(self::ALICE)]);

		$own = $this->service->forAccount($this->person(self::ALICE), $this->person(self::ALICE));
		$this->assertSame(17, $own[0]->getViewCount());

		$this->followService->method('getLinksBetweenPersons')
			->willReturn(['follower' => false, 'following' => true]);
		$theirs = $this->service->forAccount($this->person(self::BOB), $this->person(self::ALICE));
		$this->assertSame(0, $theirs[0]->getViewCount(), 'a viewer was told how many people watched');
	}

	public function testSeenIsResolvedForTheWholeCarouselInOneQuery(): void {
		$this->storiesRequest->method('getLiveByActor')->willReturn([$this->story(self::ALICE, 1)]);
		$this->storiesRequest->method('getLiveByActors')->willReturn([$this->story(self::BOB, 2)]);
		$this->followService->method('getFollowing')->willReturn([]);
		$this->storiesRequest->expects($this->once())->method('seenAmong')->willReturn([2]);

		$carousel = $this->service->carousel($this->person(self::ALICE));

		$this->assertCount(2, $carousel);
		$this->assertFalse($carousel[0]->isSeen());
		$this->assertTrue($carousel[1]->isSeen());
	}
}
