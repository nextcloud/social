<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use DateTime;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class StreamServiceTest extends TestCase {
	private const SOCIAL_URL = 'https://social.example/';
	private const ACTOR_ID = 'https://social.example/@alice';
	private const ACTOR_FOLLOWERS = 'https://social.example/@alice/followers';
	private const GENERATED_ID = 'https://social.example/@alice/1234567890';

	private StreamRequest|MockObject $streamRequest;
	private ActivityService|MockObject $activityService;
	private CacheActorService|MockObject $cacheActorService;
	private ConfigService|MockObject $configService;
	private CurlService|MockObject $curlService;
	private LinkPreviewService|MockObject $linkPreviewService;
	private StreamService $service;

	protected function setUp(): void {
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->curlService = $this->createMock(CurlService::class);
		$this->linkPreviewService = $this->createMock(LinkPreviewService::class);

		$this->configService->method('generateId')->willReturn(self::GENERATED_ID);
		$this->configService->method('getSocialUrl')->willReturn(self::SOCIAL_URL);

		$this->service = new StreamService(
			$this->createMock(IURLGenerator::class),
			$this->streamRequest,
			$this->activityService,
			$this->cacheActorService,
			$this->configService,
			$this->curlService,
			$this->linkPreviewService,
			new NullLogger()
		);
	}

	private function actor(): Person {
		$actor = new Person();
		$actor->setId(self::ACTOR_ID);
		$actor->setPreferredUsername('alice');
		$actor->setFollowers(self::ACTOR_FOLLOWERS);
		$actor->setInbox(self::ACTOR_ID . '/inbox');
		$actor->setOutbox(self::ACTOR_ID . '/outbox');
		$actor->setLocal(true);

		return $actor;
	}

	private function remoteActor(string $id = 'https://remote.example/users/bob'): Person {
		$actor = new Person();
		$actor->setId($id);
		$actor->setPreferredUsername('bob');
		$actor->setAccount('bob@remote.example');
		$actor->setInbox($id . '/inbox');
		$actor->setSharedInbox('https://remote.example/inbox');
		$actor->setFollowers($id . '/followers');
		$actor->setOutbox($id . '/outbox');

		return $actor;
	}

	private function note(string $id, string $attributedTo = self::ACTOR_ID, string $inReplyTo = ''): Note {
		$note = new Note();
		$note->setId($id);
		$note->setAttributedTo($attributedTo);
		$note->setInReplyTo($inReplyTo);

		return $note;
	}

	/**
	 * @param InstancePath[] $paths
	 */
	private function assertHasInstancePath(array $paths, string $uri, int $type, int $priority): void {
		foreach ($paths as $path) {
			if ($path->getUri() === $uri && $path->getType() === $type && $path->getPriority() === $priority) {
				$this->addToAssertionCount(1);

				return;
			}
		}

		$this->fail('No instance path for ' . $uri . ' (type ' . $type . ', priority ' . $priority . ') in ' . json_encode($paths));
	}

	// assignItem() / assignStream()

	public function testAssignItemGeneratesIdFromActorAndMarksItemLocal(): void {
		$this->configService->expects($this->once())
			->method('generateId')
			->with('@alice');

		$note = new Note();
		$this->service->assignItem($note, $this->actor(), Stream::TYPE_PUBLIC);

		$this->assertSame(self::GENERATED_ID, $note->getId());
		$this->assertTrue($note->isLocal());
		$this->assertNotSame('', $note->getPublished());
		$this->assertEqualsWithDelta(time(), (new DateTime($note->getPublished()))->getTimestamp(), 5);
		// Stream items also get their numeric publication time
		$this->assertEqualsWithDelta(time(), $note->getPublishedTime(), 5);
	}

	public function testAssignItemOnNonStreamItemDoesNotNeedAStream(): void {
		$announce = new Announce();
		$this->service->assignItem($announce, $this->actor(), Stream::TYPE_ANNOUNCE);

		$this->assertTrue($announce->isLocal());
		$this->assertSame(self::GENERATED_ID, $announce->getId());
	}

	public function testAssignStreamConvertsPublishedDateToTimestamp(): void {
		$note = new Note();
		$note->setPublished('2026-01-02T03:04:05+00:00');

		$this->service->assignStream($note);

		$this->assertSame((new DateTime('2026-01-02T03:04:05+00:00'))->getTimestamp(), $note->getPublishedTime());
	}

	/**
	 * @return array<string, array{string, string, string[], bool, bool}>
	 */
	public function visibilityProvider(): array {
		return [
			'public: as:Public in to, followers in cc' => [
				Stream::TYPE_PUBLIC, ACore::CONTEXT_PUBLIC, [self::ACTOR_FOLLOWERS], true, true,
			],
			'unlisted: followers in to, as:Public in cc' => [
				Stream::TYPE_UNLISTED, self::ACTOR_FOLLOWERS, [ACore::CONTEXT_PUBLIC], true, true,
			],
			'followers-only: followers in to, nobody in cc' => [
				Stream::TYPE_FOLLOWERS, self::ACTOR_FOLLOWERS, [], false, true,
			],
			'direct: nobody addressed until mentions are added' => [
				Stream::TYPE_DIRECT, '', [], false, false,
			],
			'announce: followers in cc only' => [
				Stream::TYPE_ANNOUNCE, '', [self::ACTOR_FOLLOWERS], false, true,
			],
			// An unknown visibility must never be treated as public: that is how
			// a Mastodon client's `private` (which this app calls `followers`)
			// used to be published to the whole Fediverse.
			'unknown type is addressed to nobody' => [
				'', '', [], false, false,
			],
		];
	}

	/**
	 * @dataProvider visibilityProvider
	 */
	public function testAssignItemAddressesRecipientsPerVisibility(
		string $type,
		string $expectedTo,
		array $expectedCc,
		bool $expectedPublic,
		bool $expectFollowersPath,
	): void {
		$note = new Note();
		$this->service->assignItem($note, $this->actor(), $type);

		$this->assertSame($expectedTo, $note->getTo());
		$this->assertSame([], $note->getToArray());
		$this->assertSame($expectedCc, $note->getCcArray());
		$this->assertSame($expectedPublic, $note->isPublic());

		if ($expectFollowersPath) {
			$this->assertCount(1, $note->getInstancePaths());
			$this->assertHasInstancePath(
				$note->getInstancePaths(), self::ACTOR_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
			);
		} else {
			$this->assertSame([], $note->getInstancePaths());
		}
	}

	// detectType()

	public function testDetectTypeMarksPublicStreamsAsPublicTimeline(): void {
		$note = new Note();
		$note->setTo(ACore::CONTEXT_PUBLIC);

		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->service->detectType($note);

		$this->assertSame(Stream::TYPE_PUBLIC, $note->getTimeline());
		$this->assertSame(Note::TYPE, $note->getType());
	}

	public function testDetectTypeFindsPublicInToArrayToo(): void {
		$note = new Note();
		$note->setTo(self::ACTOR_FOLLOWERS);
		$note->addToArray(ACore::CONTEXT_PUBLIC);

		$this->service->detectType($note);

		$this->assertSame(Stream::TYPE_PUBLIC, $note->getTimeline());
	}

	public function testDetectTypeMarksUnlistedStreamsAsUnlistedTimelineWithoutRewritingTheirType(): void {
		$note = new Note();
		$note->setTo(self::ACTOR_FOLLOWERS);
		$note->addCc(ACore::CONTEXT_PUBLIC);

		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->service->detectType($note);

		$this->assertSame(Stream::TYPE_UNLISTED, $note->getTimeline());
		// the timeline is not the ActivityPub type: an unlisted Note is still a Note
		$this->assertSame(Note::TYPE, $note->getType());
	}

	/**
	 * @return array<string, array{string, string[]}>
	 */
	public function followersAddressingProvider(): array {
		return [
			'followers collection in to' => [self::ACTOR_FOLLOWERS, []],
			'followers collection in cc' => ['', [self::ACTOR_FOLLOWERS]],
		];
	}

	/**
	 * @dataProvider followersAddressingProvider
	 */
	public function testDetectTypeMarksStreamsAddressedToTheFollowersCollectionAsFollowers(
		string $to,
		array $cc,
	): void {
		$note = new Note();
		$note->setAttributedTo(self::ACTOR_ID);
		$note->setTo($to);
		$note->setCcArray($cc);

		$this->cacheActorService->expects($this->once())
			->method('getFromId')
			->with(self::ACTOR_ID)
			->willReturn($this->actor());

		$this->service->detectType($note);

		$this->assertSame(Stream::TYPE_FOLLOWERS, $note->getTimeline());
	}

	public function testDetectTypeMarksStreamsAddressedToASingleRecipientAsDirect(): void {
		$note = new Note();
		$note->setAttributedTo(self::ACTOR_ID);
		$note->setTo($this->remoteActor()->getId());

		$this->cacheActorService->method('getFromId')->with(self::ACTOR_ID)->willReturn($this->actor());

		// classifying a stream never writes anything to the HTTP response body
		$this->expectOutputString('');

		$this->service->detectType($note);

		$this->assertSame(Stream::TYPE_DIRECT, $note->getTimeline());
	}

	public function testDetectTypeLeavesTheTimelineUnsetWhenTheAuthorCannotBeResolved(): void {
		$note = new Note();
		$note->setAttributedTo('https://remote.example/users/ghost');
		$note->setTo($this->remoteActor()->getId());

		$this->cacheActorService->method('getFromId')
			->willThrowException(new CacheActorDoesNotExistException());

		$this->service->detectType($note);

		$this->assertSame('', $note->getTimeline());
	}

	// addRecipient() / addRecipients()

	public function testAddRecipientOnDirectMessageAddressesMentionedActorInTo(): void {
		$bob = $this->remoteActor();
		$this->cacheActorService->expects($this->once())
			->method('getFromAccount')
			->with('bob@remote.example', true)
			->willReturn($bob);

		$note = new Note();
		$this->service->addRecipient($note, Stream::TYPE_DIRECT, 'bob@remote.example');

		$this->assertSame([$bob->getId()], $note->getToArray());
		$this->assertSame([], $note->getCcArray());
		$this->assertTrue($note->isFilterDuplicate());
		$this->assertSame(
			[['type' => 'Mention', 'href' => $bob->getId(), 'name' => '@bob@remote.example']],
			$note->getTags()
		);
		$this->assertCount(1, $note->getInstancePaths());
		$this->assertHasInstancePath(
			$note->getInstancePaths(), $bob->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function nonDirectTypeProvider(): array {
		return [
			'public' => [Stream::TYPE_PUBLIC],
			'unlisted' => [Stream::TYPE_UNLISTED],
			'followers' => [Stream::TYPE_FOLLOWERS],
		];
	}

	/**
	 * @dataProvider nonDirectTypeProvider
	 */
	public function testAddRecipientOnNonDirectPostAddressesMentionedActorInCc(string $type): void {
		$bob = $this->remoteActor();
		$this->cacheActorService->method('getFromAccount')->willReturn($bob);

		$note = new Note();
		$this->service->addRecipient($note, $type, 'bob@remote.example');

		$this->assertSame([], $note->getToArray());
		$this->assertSame([$bob->getId()], $note->getCcArray());
		$this->assertFalse($note->isFilterDuplicate());
		$this->assertSame('Mention', $note->getTags()[0]['type']);
		$this->assertHasInstancePath(
			$note->getInstancePaths(), $bob->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM
		);
	}

	public function testAddRecipientIgnoresEmptyAccount(): void {
		$this->cacheActorService->expects($this->never())->method('getFromAccount');

		$note = new Note();
		$this->service->addRecipient($note, Stream::TYPE_PUBLIC, '');

		$this->assertSame([], $note->getTags());
		$this->assertSame([], $note->getInstancePaths());
	}

	public function testAddRecipientIgnoresUnresolvableAccount(): void {
		$this->cacheActorService->method('getFromAccount')
			->willThrowException(new CacheActorDoesNotExistException());

		$note = new Note();
		$this->service->addRecipient($note, Stream::TYPE_DIRECT, 'nobody@remote.example');

		$this->assertSame([], $note->getToArray());
		$this->assertSame([], $note->getCcArray());
		$this->assertSame([], $note->getTags());
		$this->assertSame([], $note->getInstancePaths());
	}

	public function testAddRecipientsResolvesEveryAccount(): void {
		$bob = $this->remoteActor('https://remote.example/users/bob');
		$carol = $this->remoteActor('https://other.example/users/carol');
		$this->cacheActorService->expects($this->exactly(2))
			->method('getFromAccount')
			->willReturnMap([
				['bob@remote.example', true, $bob],
				['carol@other.example', true, $carol],
			]);

		$note = new Note();
		$this->service->addRecipients($note, Stream::TYPE_PUBLIC, ['bob@remote.example', 'carol@other.example']);

		$this->assertSame([$bob->getId(), $carol->getId()], $note->getCcArray());
		$this->assertCount(2, $note->getTags('Mention'));
		$this->assertCount(2, $note->getInstancePaths());
	}

	// addHashtag() / addHashtags()

	public function testAddHashtagAddsLowercasedTagLink(): void {
		$note = new Note();
		$this->service->addHashtag($note, 'NextCloud');

		$this->assertSame(
			[['type' => 'Hashtag', 'href' => self::SOCIAL_URL . 'tag/nextcloud', 'name' => '#NextCloud']],
			$note->getTags()
		);
	}

	public function testAddHashtagsStoresListAndTagsEachEntry(): void {
		$note = new Note();
		$this->service->addHashtags($note, ['Fediverse', 'Nextcloud']);

		$this->assertSame(['Fediverse', 'Nextcloud'], $note->getHashtags());
		$this->assertCount(2, $note->getTags('Hashtag'));
		$this->assertSame('#Fediverse', $note->getTags()[0]['name']);
		$this->assertSame(self::SOCIAL_URL . 'tag/nextcloud', $note->getTags()[1]['href']);
	}

	public function testAddHashtagSkipsTagWhenSocialUrlIsNotConfigured(): void {
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getSocialUrl')->willThrowException(new SocialAppConfigException());
		$service = new StreamService(
			$this->createMock(IURLGenerator::class),
			$this->streamRequest,
			$this->activityService,
			$this->cacheActorService,
			$configService,
			$this->curlService,
			$this->linkPreviewService,
			new NullLogger()
		);

		$note = new Note();
		$service->addHashtags($note, ['Nextcloud']);

		$this->assertSame(['Nextcloud'], $note->getHashtags());
		$this->assertSame([], $note->getTags());
	}

	// replyTo()

	public function testReplyToWiresParentAndAddressesItsAuthor(): void {
		$parentId = 'https://remote.example/notes/parent';
		$bob = $this->remoteActor();
		$this->streamRequest->expects($this->once())
			->method('getStreamById')
			->with($parentId)
			->willReturn($this->note($parentId, $bob->getId()));
		$this->cacheActorService->expects($this->once())
			->method('getFromId')
			->with($bob->getId())
			->willReturn($bob);

		$note = new Note();
		$this->service->replyTo($note, $parentId);

		$this->assertSame($parentId, $note->getInReplyTo());
		$this->assertCount(1, $note->getInstancePaths());
		$this->assertHasInstancePath(
			$note->getInstancePaths(), $bob->getSharedInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH
		);
	}

	public function testReplyToWithoutParentIsANoop(): void {
		$this->streamRequest->expects($this->never())->method('getStreamById');

		$note = new Note();
		$this->service->replyTo($note, '');

		$this->assertSame('', $note->getInReplyTo());
		$this->assertSame([], $note->getInstancePaths());
	}

	public function testReplyToUnknownParentFails(): void {
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$note = new Note();
		try {
			$this->service->replyTo($note, 'https://remote.example/notes/missing');
			$this->fail('expected StreamNotFoundException');
		} catch (StreamNotFoundException $e) {
			$this->assertSame('', $note->getInReplyTo());
			$this->assertSame([], $note->getInstancePaths());
		}
	}

	// deleteLocalItem()

	public function testDeleteLocalItemFederatesDeleteAndRemovesRow(): void {
		$item = $this->note('https://social.example/@alice/1');
		$item->setLocal(true);

		$this->cacheActorService->method('getFromId')->with(self::ACTOR_ID)->willReturn($this->actor());
		$this->activityService->expects($this->once())
			->method('deleteActivity')
			->with($this->identicalTo($item))
			->willReturn('token');
		$this->streamRequest->expects($this->once())
			->method('deleteById')
			->with('https://social.example/@alice/1', Note::TYPE);

		$this->service->deleteLocalItem($item, Note::TYPE);

		$this->assertSame(self::ACTOR_ID, $item->getActorId());
		$this->assertHasInstancePath(
			$item->getInstancePaths(), self::ACTOR_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
		);
	}

	public function testDeleteLocalItemStillDeletesWhenAuthorCannotBeResolved(): void {
		$item = $this->note('https://social.example/@alice/1');
		$item->setLocal(true);

		$this->cacheActorService->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->activityService->expects($this->once())->method('deleteActivity')->willReturn('token');
		$this->streamRequest->expects($this->once())->method('deleteById')->with($item->getId(), '');

		$this->service->deleteLocalItem($item);

		$this->assertSame([], $item->getInstancePaths());
	}

	public function testDeleteLocalItemRefusesRemoteItems(): void {
		$item = $this->note('https://remote.example/notes/1', 'https://remote.example/users/bob');
		$item->setLocal(false);

		$this->activityService->expects($this->never())->method('deleteActivity');
		$this->streamRequest->expects($this->never())->method('deleteById');

		$this->service->deleteLocalItem($item, Note::TYPE);

		$this->assertSame('', $item->getActorId());
	}

	// lookups delegating to StreamRequest

	public function testSetViewerIsPassedToStreamRequest(): void {
		$viewer = $this->actor();
		$this->streamRequest->expects($this->once())->method('setViewer')->with($this->identicalTo($viewer));

		$this->service->setViewer($viewer);
	}

	public function testGetStreamByIdPassesViewerFlagAndFormat(): void {
		$note = $this->note('https://social.example/@alice/1');
		$this->streamRequest->expects($this->once())
			->method('getStreamById')
			->with('https://social.example/@alice/1', true, ACore::FORMAT_LOCAL)
			->willReturn($note);

		$this->assertSame($note, $this->service->getStreamById('https://social.example/@alice/1', true, ACore::FORMAT_LOCAL));
	}

	public function testGetStreamByIdDefaultsToActivityPubFormatWithoutViewer(): void {
		$note = $this->note('https://social.example/@alice/1');
		$this->streamRequest->expects($this->once())
			->method('getStreamById')
			->with('https://social.example/@alice/1', false, ACore::FORMAT_ACTIVITYPUB)
			->willReturn($note);

		$this->assertSame($note, $this->service->getStreamById('https://social.example/@alice/1'));
	}

	public function testGetStreamByNidDelegates(): void {
		$note = $this->note('https://social.example/@alice/1');
		$this->streamRequest->expects($this->once())->method('getStreamByNid')->with(42)->willReturn($note);

		$this->assertSame($note, $this->service->getStreamByNid(42));
	}

	public function testGetStreamByNidPropagatesNotFound(): void {
		$this->streamRequest->method('getStreamByNid')->willThrowException(new StreamNotFoundException());

		$this->expectException(StreamNotFoundException::class);
		$this->service->getStreamByNid(42);
	}

	public function testUpdateStreamDelegates(): void {
		$note = $this->note('https://social.example/@alice/1');
		$this->streamRequest->expects($this->once())->method('update')->with($this->identicalTo($note));

		$this->service->updateStream($note);
	}

	public function testGetTimelinePassesProbeOptions(): void {
		$options = new ProbeOptions();
		$note = $this->note('https://social.example/@alice/1');
		$this->streamRequest->expects($this->once())
			->method('getTimeline')
			->with($this->identicalTo($options))
			->willReturn([$note]);

		$this->assertSame([$note], $this->service->getTimeline($options));
	}

	/**
	 * @return array<string, array{string, array, string, array}>
	 */
	public function timelineDelegationProvider(): array {
		return [
			'home' => ['getStreamHome', [10, 20, ACore::FORMAT_LOCAL], 'getTimelineHome_dep', [10, 20, ACore::FORMAT_LOCAL]],
			'home defaults' => ['getStreamHome', [], 'getTimelineHome_dep', [0, 5, ACore::FORMAT_ACTIVITYPUB]],
			'notifications' => ['getStreamNotifications', [10, 20], 'getTimelineNotifications_dep', [10, 20]],
			'account' => ['getStreamAccount', ['https://remote.example/users/bob', 10, 20], 'getTimelineAccount_dep', ['https://remote.example/users/bob', 10, 20]],
			'direct' => ['getStreamDirect', [10, 20], 'getTimelineDirect_dep', [10, 20]],
			'local timeline restricts to local posts' => ['getStreamLocalTimeline', [10, 20], 'getTimelineGlobal_dep', [10, 20, true]],
			'global timeline includes remote posts' => ['getStreamGlobalTimeline', [10, 20], 'getTimelineGlobal_dep', [10, 20, false]],
			'tag' => ['getStreamLocalTag', ['nextcloud', 10, 20], 'getTimelineTag', ['nextcloud', 10, 20]],
			'liked' => ['getStreamLiked', [10, 20], 'getTimelineLiked', [10, 20]],
			'replies' => ['getRepliesByParentId', ['https://social.example/@alice/1', 10, 20, true], 'getRepliesByParentId', ['https://social.example/@alice/1', 10, 20, true]],
			'replies defaults' => ['getRepliesByParentId', ['https://social.example/@alice/1'], 'getRepliesByParentId', ['https://social.example/@alice/1', 0, 5, false]],
		];
	}

	/**
	 * @dataProvider timelineDelegationProvider
	 */
	public function testTimelineGettersPassFiltersThrough(
		string $serviceMethod,
		array $args,
		string $requestMethod,
		array $expectedArgs,
	): void {
		$note = $this->note('https://social.example/@alice/1');
		$this->streamRequest->expects($this->once())
			->method($requestMethod)
			->with(...$expectedArgs)
			->willReturn([$note]);

		$this->assertSame([$note], $this->service->$serviceMethod(...$args));
	}

	public function testInternalTimelineIsNotImplementedYet(): void {
		$this->streamRequest->expects($this->never())->method($this->anything());

		$this->assertSame([], $this->service->getStreamInternalTimeline(0, 20));
	}

	// getContextByNid()

	public function testGetContextByNidCollectsAncestorsInThreadOrderAndDescendants(): void {
		$post = $this->note('https://social.example/@alice/3', self::ACTOR_ID, 'https://social.example/@alice/2');
		$parent = $this->note('https://social.example/@alice/2', self::ACTOR_ID, 'https://social.example/@alice/1');
		$root = $this->note('https://social.example/@alice/1');
		$reply = $this->note('https://social.example/@alice/4', self::ACTOR_ID, $post->getId());

		$this->streamRequest->method('getStreamByNid')->with(3)->willReturn($post);
		$this->streamRequest->expects($this->exactly(2))
			->method('getStreamById')
			->willReturnMap([
				[$parent->getId(), true, ACore::FORMAT_ACTIVITYPUB, $parent],
				[$root->getId(), true, ACore::FORMAT_ACTIVITYPUB, $root],
			]);
		$this->streamRequest->method('getDescendants')->with($post->getId())->willReturn([$reply]);

		$context = $this->service->getContextByNid(3);

		$this->assertSame([$root, $parent], $context['ancestors']);
		$this->assertSame([$reply], $context['descendants']);
		$this->assertSame(ACore::FORMAT_LOCAL, $root->getExportFormat());
		$this->assertSame(ACore::FORMAT_LOCAL, $parent->getExportFormat());
	}

	public function testGetContextByNidStopsAtAncestorsTheViewerCannotSee(): void {
		$post = $this->note('https://social.example/@alice/3', self::ACTOR_ID, 'https://social.example/@alice/2');
		$this->streamRequest->method('getStreamByNid')->willReturn($post);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->streamRequest->method('getDescendants')->willReturn([]);

		$context = $this->service->getContextByNid(3);

		$this->assertSame(['ancestors' => [], 'descendants' => []], $context);
	}

	public function testGetContextByNidLimitsAncestorDepth(): void {
		$post = $this->note('https://social.example/@alice/9', self::ACTOR_ID, 'https://social.example/@alice/8');
		$this->streamRequest->method('getStreamByNid')->willReturn($post);
		$this->streamRequest->method('getStreamById')->willReturnCallback(function (string $id): Note {
			$n = (int)substr($id, -1);

			return $this->note($id, self::ACTOR_ID, $n > 1 ? 'https://social.example/@alice/' . ($n - 1) : '');
		});
		$this->streamRequest->method('getDescendants')->willReturn([]);

		$context = $this->service->getContextByNid(9);

		$this->assertCount(5, $context['ancestors']);
		$this->assertSame('https://social.example/@alice/4', $context['ancestors'][0]->getId());
		$this->assertSame('https://social.example/@alice/8', $context['ancestors'][4]->getId());
	}

	// getAuthorFromPostId()

	public function testGetAuthorFromPostIdResolvesAttributedActor(): void {
		$bob = $this->remoteActor();
		$this->streamRequest->method('getStreamById')
			->with('https://remote.example/notes/1')
			->willReturn($this->note('https://remote.example/notes/1', $bob->getId()));
		$this->cacheActorService->expects($this->once())->method('getFromId')->with($bob->getId())->willReturn($bob);

		$this->assertSame($bob, $this->service->getAuthorFromPostId('https://remote.example/notes/1'));
	}

	public function testGetAuthorFromPostIdFailsOnUnknownPost(): void {
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->expectException(StreamNotFoundException::class);
		$this->service->getAuthorFromPostId('https://remote.example/notes/missing');
	}

	// getOutboxCollection()

	public function testGetOutboxCollectionDescribesActorOutbox(): void {
		$actor = $this->actor();
		$actor->setDetailArray('count', ['post' => 17, 'followers' => 3]);

		$collection = $this->service->getOutboxCollection($actor);

		$this->assertSame('OrderedCollection', $collection->getType());
		$this->assertSame(self::ACTOR_ID . '/outbox', $collection->getId());
		$this->assertSame(17, $collection->getTotalItems());
		$this->assertSame(self::ACTOR_ID . '/outbox?page=1', $collection->getFirst());
		$this->assertSame(self::ACTOR_ID . '/outbox?page=1', $collection->getLast());
	}

	public function testGetOutboxCollectionWithoutCountsIsEmpty(): void {
		$collection = $this->service->getOutboxCollection($this->actor());

		$this->assertSame(0, $collection->getTotalItems());
	}

	// syncRemoteTimeline()

	public function testSyncRemoteTimelineIgnoresLocalActors(): void {
		$this->curlService->expects($this->never())->method('retrieveObject');
		$this->streamRequest->expects($this->never())->method('save');

		$this->assertSame(0, $this->service->syncRemoteTimeline($this->actor()));
	}

	public function testSyncRemoteTimelineNeedsAnOutbox(): void {
		$bob = $this->remoteActor();
		$bob->setOutbox('');
		$this->curlService->expects($this->never())->method('retrieveObject');

		$this->assertSame(0, $this->service->syncRemoteTimeline($bob));
	}

	public function testSyncRemoteTimelineFollowsFirstPageAndStoresUnknownNotes(): void {
		$bob = $this->remoteActor();
		$noteData = [
			'id' => 'https://remote.example/notes/new',
			'type' => 'Note',
			'url' => 'https://remote.example/@bob/new',
			'attributedTo' => $bob->getId(),
			'published' => '2026-01-02T03:04:05Z',
			'content' => '<p>Hello #Fedi</p>',
			'summary' => 'cw',
			'sensitive' => true,
			'inReplyTo' => 'https://remote.example/notes/0',
			'conversation' => 'https://remote.example/contexts/1',
			'to' => [ACore::CONTEXT_PUBLIC],
			'cc' => $bob->getFollowers(),
			'tag' => [
				['type' => 'Hashtag', 'name' => '#Fedi', 'href' => 'https://remote.example/tags/fedi'],
				['type' => 'Mention', 'name' => '@alice@social.example', 'href' => self::ACTOR_ID],
			],
			'likes' => ['totalItems' => 3],
			'shares' => ['totalItems' => 2],
			'replies' => ['totalItems' => 1],
		];
		$page = [
			'type' => 'OrderedCollectionPage',
			'orderedItems' => [
				['type' => 'Create', 'object' => $noteData],
				['type' => 'Note', 'id' => 'https://remote.example/notes/known', 'content' => 'already here'],
				['type' => 'Announce', 'object' => 'https://other.example/notes/1'],
				['type' => 'Note', 'content' => 'no id'],
			],
		];

		$this->curlService->expects($this->exactly(2))
			->method('retrieveObject')
			->willReturnMap([
				[$bob->getOutbox(), true, ['type' => 'OrderedCollection', 'first' => $bob->getOutbox() . '?page=true']],
				[$bob->getOutbox() . '?page=true', true, $page],
			]);
		$this->streamRequest->method('getStreamById')->willReturnCallback(function (string $id): Stream {
			if ($id === 'https://remote.example/notes/known') {
				return $this->note($id);
			}
			throw new StreamNotFoundException();
		});

		$saved = null;
		$this->streamRequest->expects($this->once())
			->method('save')
			->willReturnCallback(function (Stream $stream) use (&$saved): void {
				$saved = $stream;
			});

		$this->assertSame(1, $this->service->syncRemoteTimeline($bob));

		$this->assertInstanceOf(Note::class, $saved);
		$this->assertSame('https://remote.example/notes/new', $saved->getId());
		$this->assertSame('https://remote.example/@bob/new', $saved->getUrl());
		$this->assertSame($bob->getId(), $saved->getAttributedTo());
		$this->assertSame('<p>Hello #Fedi</p>', $saved->getContent());
		$this->assertSame('cw', $saved->getSummary());
		$this->assertTrue($saved->isSensitive());
		$this->assertFalse($saved->isLocal());
		$this->assertSame('https://remote.example/notes/0', $saved->getInReplyTo());
		$this->assertSame('https://remote.example/contexts/1', $saved->getConversation());
		$this->assertSame([ACore::CONTEXT_PUBLIC], $saved->getToArray());
		$this->assertSame([$bob->getFollowers()], $saved->getCcArray());
		$this->assertSame(['Fedi'], $saved->getHashtags());
		$this->assertCount(2, $saved->getTags());
		$this->assertSame((new DateTime('2026-01-02T03:04:05Z'))->getTimestamp(), $saved->getPublishedTime());
		$this->assertSame(3, $saved->getDetailInt('likes'));
		$this->assertSame(3, $saved->getDetailInt('remote_likes'));
		$this->assertSame(2, $saved->getDetailInt('boosts'));
		$this->assertSame(1, $saved->getDetailInt('replies'));
		$this->assertSame($noteData, json_decode($saved->getSource(), true));
	}

	public function testSyncRemoteTimelineUsesEmbeddedFirstPage(): void {
		$bob = $this->remoteActor();
		$this->curlService->expects($this->once())
			->method('retrieveObject')
			->with($bob->getOutbox())
			->willReturn([
				'type' => 'OrderedCollection',
				'first' => [
					'items' => [
						['type' => 'Note', 'id' => 'https://remote.example/notes/a', 'content' => 'a'],
						['type' => 'Note', 'id' => 'https://remote.example/notes/b', 'content' => 'b'],
					],
				],
			]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->streamRequest->expects($this->exactly(2))->method('save');

		$this->assertSame(2, $this->service->syncRemoteTimeline($bob));
	}

	public function testSyncRemoteTimelineFallsBackToActorForMissingAttribution(): void {
		$bob = $this->remoteActor();
		$this->curlService->method('retrieveObject')->willReturn([
			'orderedItems' => [['type' => 'Note', 'id' => 'https://remote.example/notes/a', 'content' => 'a']],
		]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->streamRequest->expects($this->once())
			->method('save')
			->with($this->callback(fn (Stream $s): bool => $s->getAttributedTo() === $bob->getId()));

		$this->assertSame(1, $this->service->syncRemoteTimeline($bob));
	}

	public function testSyncRemoteTimelineSkipsItemsForgedForAnotherServersActor(): void {
		// The outbox is what a remote server says about itself, so it may only yield
		// notes that live on that server and are attributed to the actor whose outbox
		// is being read. A note whose attributedTo points at another instance is that
		// server speaking for someone it does not host and must never be stored.
		$bob = $this->remoteActor();
		$legit = [
			'type' => 'Note',
			'id' => 'https://remote.example/notes/1',
			'attributedTo' => $bob->getId(),
			'content' => 'genuine',
		];
		$forged = [
			'type' => 'Note',
			'id' => 'https://remote.example/notes/2',
			'attributedTo' => 'https://victim.example/users/alice',
			'content' => 'impersonation',
		];
		$this->curlService->method('retrieveObject')->willReturn([
			'orderedItems' => [$legit, $forged],
		]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$saved = [];
		$this->streamRequest->expects($this->once())
			->method('save')
			->willReturnCallback(function (Stream $stream) use (&$saved): void {
				$saved[] = $stream->getId();
			});

		$this->assertSame(1, $this->service->syncRemoteTimeline($bob));
		$this->assertSame(['https://remote.example/notes/1'], $saved);
	}

	public function testSyncRemoteTimelineSkipsNotesHostedOnAnotherServer(): void {
		// Even when attribution is consistent with the note, a note whose id lives on
		// a different host than the actor is not something this outbox may vouch for.
		$bob = $this->remoteActor();
		$foreign = [
			'type' => 'Note',
			'id' => 'https://victim.example/notes/9',
			'attributedTo' => 'https://victim.example/users/alice',
			'content' => 'not bobs to publish',
		];
		$this->curlService->method('retrieveObject')->willReturn([
			'orderedItems' => [$foreign],
		]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->streamRequest->expects($this->never())->method('save');

		$this->assertSame(0, $this->service->syncRemoteTimeline($bob));
	}

	public function testSyncRemoteTimelineSwallowsNetworkFailures(): void {
		$bob = $this->remoteActor();
		$this->curlService->method('retrieveObject')->willThrowException(new RequestNetworkException());
		$this->streamRequest->expects($this->never())->method('save');

		$this->assertSame(0, $this->service->syncRemoteTimeline($bob));
	}

	public function testSyncRemoteTimelineKeepsGoingWhenOneItemFailsToSave(): void {
		$bob = $this->remoteActor();
		$this->curlService->method('retrieveObject')->willReturn([
			'orderedItems' => [
				['type' => 'Note', 'id' => 'https://remote.example/notes/a', 'content' => 'a'],
				['type' => 'Note', 'id' => 'https://remote.example/notes/b', 'content' => 'b'],
			],
		]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->streamRequest->expects($this->exactly(2))
			->method('save')
			->willReturnCallback(function (Stream $stream): void {
				if ($stream->getId() === 'https://remote.example/notes/a') {
					throw new \RuntimeException('db hiccup');
				}
			});

		$this->assertSame(1, $this->service->syncRemoteTimeline($bob));
	}
}
