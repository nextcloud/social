<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StoriesRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Collection;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Story;
use OCA\Social\Model\Report;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AvatarService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CollectionService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\PixelfedService;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\StoryService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PixelfedServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';
	private const BOB = 'https://cloud.example/apps/social/@bob';
	private const CAROL = 'https://remote.example/users/carol';

	private StoryService|MockObject $storyService;
	private StoriesRequest|MockObject $storiesRequest;
	private CollectionService|MockObject $collectionService;
	private FollowService|MockObject $followService;
	private CacheActorService|MockObject $cacheActorService;
	private SearchService|MockObject $searchService;
	private ReportService|MockObject $reportService;
	private StreamService|MockObject $streamService;
	private InstanceService|MockObject $instanceService;
	private PixelfedService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->storyService = $this->createMock(StoryService::class);
		$this->storiesRequest = $this->createMock(StoriesRequest::class);
		$this->collectionService = $this->createMock(CollectionService::class);
		$this->followService = $this->createMock(FollowService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->searchService = $this->createMock(SearchService::class);
		$this->reportService = $this->createMock(ReportService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->instanceService = $this->createMock(InstanceService::class);
		$this->instanceService->method('supportedMimeTypes')->willReturn(['image/jpeg', 'video/mp4']);
		$this->instanceService->method('maxUploadSize')->willReturn(10 * 1024 * 1024);

		$configService = $this->createMock(ConfigService::class);
		$configService->method('getSocialUrl')->willReturn('https://cloud.example/apps/social/');

		$this->cacheActorService->method('getFromId')->willReturnCallback(fn (string $id): Person => $this->person($id));

		$this->service = new PixelfedService(
			$this->storyService,
			$this->storiesRequest,
			$this->collectionService,
			$this->followService,
			$this->cacheActorService,
			$this->searchService,
			$this->reportService,
			$this->streamService,
			$this->createMock(AvatarService::class),
			$this->createMock(AccountService::class),
			$this->instanceService,
			$configService,
		);
	}

	private function person(string $id, int $nid = 0): Person {
		$person = new Person();
		$person->setId($id)
			->setPreferredUsername(ltrim(basename($id), '@'))
			->setAccount(ltrim(basename($id), '@') . '@' . parse_url($id, PHP_URL_HOST));
		$person->setNid($nid > 0 ? $nid : crc32($id) % 1000 + 1);

		return $person;
	}

	private function story(int $id, string $owner, bool $seen, string $type = 'image'): Story {
		$media = (new MediaAttachment())->setType($type)->setUrl('https://cloud.example/media/' . $id . '.jpg');

		return (new Story())->setId($id)->setOwnerId($owner)->setSeen($seen)->setDuration(5)
			->setCreation(1_700_000_000 + $id)->setMedia($media)->setAuthor($this->person($owner));
	}

	/** One node per account, the viewer's own apart, each unseen until every story in it was. */
	public function testTheCarouselIsGroupedByAccountWithTheViewersOwnApart(): void {
		$this->storyService->method('carousel')->willReturn([
			$this->story(1, self::ALICE, true),
			$this->story(2, self::BOB, true),
			$this->story(3, self::BOB, false, 'video'),
			$this->story(4, self::CAROL, true),
		]);

		$carousel = $this->service->carousel($this->person(self::ALICE));

		$this->assertSame('alice', $carousel['self']['user']['username']);
		$this->assertTrue($carousel['self']['user']['is_author']);
		$this->assertCount(2, $carousel['nodes']);
		$bob = $carousel['nodes'][0];
		$this->assertSame('bob', $bob['user']['username']);
		$this->assertFalse($bob['seen'], 'one unseen story is an unseen ring');
		$this->assertSame(['photo', 'video'], array_column($bob['nodes'], 'type'));
		$this->assertSame('https://cloud.example/media/3.jpg', $bob['nodes'][1]['src']);
		$this->assertTrue($carousel['nodes'][1]['seen']);
	}

	public function testAViewerWithNoStoryOfTheirOwnHasNoSelfNode(): void {
		$this->storyService->method('carousel')->willReturn([$this->story(2, self::BOB, false)]);

		$carousel = $this->service->carousel($this->person(self::ALICE));

		$this->assertNull($carousel['self']);
		$this->assertCount(1, $carousel['nodes']);
	}

	/** Pixelfed's words for the visibility, a thumb from the first picture, a page of this app's own. */
	public function testACollectionIsReshapedTheWayTheAppReadsIt(): void {
		$post = new Note();
		$post->setAttachments([(new MediaAttachment())->setUrl('https://cloud.example/media/full.jpg')->setPreviewUrl('https://cloud.example/media/small.jpg')]);
		$collection = (new Collection())->setId(9)->setOwnerId(self::ALICE)->setTitle('Coast')
			->setDescription('grey days')->setVisibility(Collection::VISIBILITY_FOLLOWERS)
			->setCreation(1_700_000_000)->setUpdated(1_700_000_500)->setSize(4)->setPreview([$post]);
		$this->collectionService->method('forProfile')->willReturn([$collection]);
		$this->collectionService->method('withPreview')->willReturnArgument(0);

		$entity = $this->service->collections($this->person(self::ALICE))[0];

		$this->assertSame('9', $entity['id']);
		$this->assertSame('private', $entity['visibility']);
		$this->assertSame('https://cloud.example/media/small.jpg', $entity['thumb']);
		$this->assertSame('https://cloud.example/apps/social/collections/9', $entity['url']);
		$this->assertSame(4, $entity['post_count']);
		$this->assertNull($entity['published_at'], 'a followers-only collection is not published');
		$this->assertSame(date('c', 1_700_000_500), $entity['updated_at']);
	}

	/** A post is reported against its author and carried with the report; the reason survives as the comment. */
	public function testAReportAboutAPostNamesItsAuthorAndCarriesThePost(): void {
		$post = new Note();
		$post->setNid(17);
		$post->setAttributedTo(self::BOB);
		$this->streamService->method('getStreamByNid')->with(17)->willReturn($post);
		$this->reportService->expects($this->once())->method('reportFromLocal')
			->with(
				$this->callback(fn (Person $reporter): bool => $reporter->getId() === self::ALICE),
				$this->callback(fn (Person $target): bool => $target->getId() === self::BOB),
				['17'],
				'underage: looks young',
				Report::CATEGORY_VIOLATION,
				false
			);

		$this->service->report($this->person(self::ALICE), 'underage', '17', 'post', 'looks young');
	}

	public function testAReportAboutAStoryNamesItsOwnerAndCarriesNoPost(): void {
		$this->storiesRequest->method('getLiveById')->with(5)->willReturn((new Story())->setId(5)->setOwnerId(self::CAROL));
		$this->reportService->expects($this->once())->method('reportFromLocal')
			->with($this->anything(), $this->callback(fn (Person $t): bool => $t->getId() === self::CAROL), [], 'copyright', Report::CATEGORY_LEGAL, false);

		$this->service->report($this->person(self::ALICE), 'copyright', '5', 'story', '');
	}

	public function testAReasonTheAppDoesNotOfferIsRefused(): void {
		$this->reportService->expects($this->never())->method('reportFromLocal');

		$this->expectException(InvalidResourceException::class);
		$this->service->report($this->person(self::ALICE), 'rude', '17', 'post', '');
	}

	public function testNobodyReportsThemselves(): void {
		$this->cacheActorService->method('getFromNids')->willReturn([$this->person(self::ALICE)]);
		$this->reportService->expects($this->never())->method('reportFromLocal');

		$this->expectException(InvalidResourceException::class);
		$this->service->report($this->person(self::ALICE), 'spam', '3', 'user', '');
	}

	/** Pixelfed's mutuals are Mastodon's familiar followers. */
	public function testMutualsAreTheFamiliarFollowers(): void {
		$this->cacheActorService->method('getFromNids')->with([7])->willReturn([$this->person(self::BOB, 7)]);
		$this->followService->expects($this->once())->method('familiarFollowers')
			->with($this->anything(), $this->callback(fn (Person $t): bool => $t->getId() === self::BOB), PixelfedService::ACCOUNTS_LIMIT)
			->willReturn([$this->person(self::CAROL)]);

		$mutuals = $this->service->mutuals($this->person(self::ALICE), '7');

		$this->assertSame(self::CAROL, $mutuals[0]->getId());
	}

	/** Push is off, has no token, and says so. */
	public function testThePushStateIsHonest(): void {
		$state = $this->service->pushState($this->person(self::ALICE, 12));

		$this->assertFalse($state['notify_enabled']);
		$this->assertFalse($state['has_token']);
		$this->assertSame('12', $state['profile_id']);
		$this->assertSame(['active' => false], $this->service->nagState());
		$this->assertFalse($this->service->pushCompare($this->person(self::ALICE))['match']);
	}

	public function testTheComposerSettingsSpeakPixelfedsWords(): void {
		$alice = $this->person(self::ALICE);
		$alice->setPrivacy(Stream::TYPE_FOLLOWERS);

		$settings = $this->service->composeSettings($alice);

		$this->assertSame('private', $settings['default_scope']);
		$this->assertSame(Stream::MAX_ATTACHMENTS, $settings['max_media_attachments']);
		$this->assertSame(10 * 1024 * 1024, $settings['max_file_size']);
		$this->assertFalse($settings['media_descriptions']);
	}

	public function testAnAccountIsResolvedByWhateverTheAppNamedItWith(): void {
		$this->cacheActorService->method('getFromNids')->with([7])->willReturn([$this->person(self::BOB, 7)]);
		$this->cacheActorService->method('getFromAccount')->with('carol@remote.example', false)->willReturn($this->person(self::CAROL));

		$this->assertSame(self::BOB, $this->service->resolveAccount('7')->getId());
		$this->assertSame(self::CAROL, $this->service->resolveAccount('@carol@remote.example')->getId());
		$this->assertSame(self::CAROL, $this->service->resolveAccount(self::CAROL)->getId());
	}
}
