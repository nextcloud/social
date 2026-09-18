<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Actor;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\AnnouncementsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\FeaturedTagsRequest;
use OCA\Social\Db\FiltersRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Db\ReportsRequest;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Db\ScheduledStatusesRequest;
use OCA\Social\Db\StreamActionsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ActorService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;

require_once __DIR__ . '/../ActivityPubTestCase.php';

/**
 * Service, Group, Organization and Application actors are handled by
 * PersonInterface subclasses that add nothing of their own; these are the
 * behaviours every actor kind must share.
 */
abstract class ActorInterfaceTestCase extends ActivityPubTestCase {
	protected const BOB = self::REMOTE_URL . '/users/bob';

	/** @var ActionsRequest&MockObject */
	protected $actionsRequest;
	/** @var ReactionsRequest&MockObject */
	protected $reactionsRequest;
	/** @var CacheActorsRequest&MockObject */
	protected $cacheActorsRequest;
	/** @var CacheDocumentsRequest&MockObject */
	protected $cacheDocumentsRequest;
	/** @var FollowsRequest&MockObject */
	protected $followsRequest;
	/** @var ActorRelationRequest&MockObject */
	protected $actorRelationRequest;
	/** @var RequestQueueRequest&MockObject */
	protected $requestQueueRequest;
	/** @var StreamRequest&MockObject */
	protected $streamRequest;
	/** @var StreamDestRequest&MockObject */
	protected $streamDestRequest;
	/** @var StreamActionsRequest&MockObject */
	protected $streamActionsRequest;
	/** @var ReportsRequest&MockObject */
	protected $reportsRequest;
	/** @var ActorService&MockObject */
	protected $actorService;
	protected PersonInterface $handler;

	/** The handler under test, wired with the mocks above. */
	abstract protected function createHandler(): PersonInterface;

	/** An empty instance of the actor model this handler is registered for. */
	abstract protected function createActor(): Person;

	protected FiltersRequest|MockObject $filtersRequest;
	protected ListsRequest|MockObject $listsRequest;
	protected ConversationsRequest|MockObject $conversationsRequest;
	protected FeaturedTagsRequest|MockObject $featuredTagsRequest;
	protected AnnouncementsRequest|MockObject $announcementsRequest;
	protected ScheduledStatusesRequest|MockObject $scheduledStatusesRequest;
	protected IJobList|MockObject $jobList;

	protected function setUp(): void {
		parent::setUp();

		$this->actionsRequest = $this->createMock(ActionsRequest::class);
		$this->reactionsRequest = $this->createMock(ReactionsRequest::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->cacheDocumentsRequest = $this->createMock(CacheDocumentsRequest::class);
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->actorRelationRequest = $this->createMock(ActorRelationRequest::class);
		$this->requestQueueRequest = $this->createMock(RequestQueueRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->streamDestRequest = $this->createMock(StreamDestRequest::class);
		$this->streamActionsRequest = $this->createMock(StreamActionsRequest::class);
		$this->reportsRequest = $this->createMock(ReportsRequest::class);
		$this->filtersRequest = $this->createMock(FiltersRequest::class);
		$this->listsRequest = $this->createMock(ListsRequest::class);
		$this->conversationsRequest = $this->createMock(ConversationsRequest::class);
		$this->featuredTagsRequest = $this->createMock(FeaturedTagsRequest::class);
		$this->announcementsRequest = $this->createMock(AnnouncementsRequest::class);
		$this->scheduledStatusesRequest = $this->createMock(ScheduledStatusesRequest::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->actorService = $this->createMock(ActorService::class);

		$this->handler = $this->createHandler();
	}

	/** bob, a remote actor of the kind under test. */
	protected function bob(): Person {
		$bob = $this->createActor();
		$bob->setAccount('bob@remote.example')
			->setInbox(self::BOB . '/inbox')
			->setFollowers(self::BOB . '/followers')
			->setFollowing(self::BOB . '/following');
		$bob->setId(self::BOB);

		return $bob;
	}

	protected function nothingCached(): void {
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
	}

	public function testGetItemByIdReturnsTheCachedActor(): void {
		$bob = $this->bob();
		$this->cacheActorsRequest->method('getFromId')->with(self::BOB)->willReturn($bob);

		$this->assertSame($bob, $this->handler->getItemById(self::BOB));
	}

	public function testGetItemByIdThrowsWhenTheActorIsNotCached(): void {
		$this->nothingCached();

		$this->expectException(ItemNotFoundException::class);

		$this->handler->getItemById(self::BOB);
	}

	public function testSaveOfAnUnknownActorCreatesIt(): void {
		$this->nothingCached();
		$bob = $this->bob();

		$this->actorService->expects($this->once())->method('save')->with($this->identicalTo($bob));
		$this->actorService->expects($this->never())->method('update');

		$this->handler->save($bob);
	}

	public function testSaveOfACachedActorUpdatesIt(): void {
		$bob = $this->bob();
		$this->cacheActorsRequest->method('getFromId')->willReturn($bob);

		$this->actorService->expects($this->once())->method('update')->with($this->identicalTo($bob));
		$this->actorService->expects($this->never())->method('save');

		$this->handler->save($bob);
	}

	public function testDeleteActivityWipesEverythingTheActorLeftBehind(): void {
		$bob = $this->bob();
		$delete = $this->incoming(Delete::TYPE, self::BOB . '#delete', self::BOB, $bob);
		$this->streamDestRequest->method('getRelatedToActor')->willReturn([]);

		$this->actionsRequest->expects($this->once())->method('deleteByActor')->with(self::BOB);
		$this->cacheActorsRequest->expects($this->once())->method('deleteCacheById')->with(self::BOB);
		$this->cacheDocumentsRequest->expects($this->once())->method('deleteByParent')->with(self::BOB);
		$this->requestQueueRequest->expects($this->once())->method('deleteByAuthor')->with(self::BOB);
		$this->followsRequest->expects($this->once())->method('deleteRelatedId')->with(self::BOB);
		$this->actorRelationRequest->expects($this->once())->method('deleteRelatedId')->with(self::BOB);
		$this->streamRequest->expects($this->once())->method('deleteByAuthor')->with(self::BOB);
		$this->streamDestRequest->expects($this->once())->method('deleteRelatedToActor')->with(self::BOB);
		// their own likes, boosts, bookmarks and votes, and the reports either way
		$this->streamActionsRequest->expects($this->once())->method('deleteByActor')->with(self::BOB);
		$this->reportsRequest->expects($this->once())->method('deleteRelatedId')->with(self::BOB);

		$this->handler->activity($delete, $bob);
	}

	/**
	 * The origin check is host-wide, and a server holds more than one account:
	 * without an actor-level check any of them could delete any other, or hand
	 * `updateActor()` a profile carrying its own public key under a
	 * neighbour's id and sign as that neighbour from then on.
	 */
	public function testDeleteOfANeighbouringAccountIsRefused(): void {
		$bob = $this->bob();
		$mallory = self::REMOTE_URL . '/users/mallory';
		$delete = $this->incoming(Delete::TYPE, $mallory . '#delete', $mallory, $bob);

		$this->cacheActorsRequest->expects($this->never())->method('deleteCacheById');
		$this->streamRequest->expects($this->never())->method('deleteByAuthor');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($delete, $bob);
	}

	public function testUpdateOfANeighboursProfileIsRefused(): void {
		$bob = $this->bob();
		$bob->setPublicKey('-----BEGIN PUBLIC KEY-----mallory');
		$mallory = self::REMOTE_URL . '/users/mallory';
		$update = $this->incoming(Update::TYPE, $mallory . '#update', $mallory, $bob);

		$this->cacheActorsRequest->expects($this->never())->method('update');
		$this->cacheActorsRequest->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($update, $bob);
	}

	public function testAnAccountUpdatingItsOwnProfileIsApplied(): void {
		$bob = $this->bob();
		$update = $this->incoming(Update::TYPE, self::BOB . '#update', self::BOB, $bob);
		$stored = $this->bob();
		$stored->setCreation(1);
		$this->cacheActorsRequest->method('getFromId')->with(self::BOB)->willReturn($stored);

		$this->cacheActorsRequest->expects($this->once())->method('update')->with($this->identicalTo($bob));

		$this->handler->activity($update, $bob);
	}
}
