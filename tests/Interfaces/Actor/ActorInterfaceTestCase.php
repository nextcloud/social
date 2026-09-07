<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Actor;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ActorService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
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
	/** @var CacheActorsRequest&MockObject */
	protected $cacheActorsRequest;
	/** @var CacheDocumentsRequest&MockObject */
	protected $cacheDocumentsRequest;
	/** @var FollowsRequest&MockObject */
	protected $followsRequest;
	/** @var RequestQueueRequest&MockObject */
	protected $requestQueueRequest;
	/** @var StreamRequest&MockObject */
	protected $streamRequest;
	/** @var StreamDestRequest&MockObject */
	protected $streamDestRequest;
	/** @var ActorService&MockObject */
	protected $actorService;
	protected PersonInterface $handler;

	/** The handler under test, wired with the mocks above. */
	abstract protected function createHandler(): PersonInterface;

	/** An empty instance of the actor model this handler is registered for. */
	abstract protected function createActor(): Person;

	protected function setUp(): void {
		parent::setUp();

		$this->actionsRequest = $this->createMock(ActionsRequest::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->cacheDocumentsRequest = $this->createMock(CacheDocumentsRequest::class);
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->requestQueueRequest = $this->createMock(RequestQueueRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->streamDestRequest = $this->createMock(StreamDestRequest::class);
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
		$this->streamRequest->expects($this->once())->method('deleteByAuthor')->with(self::BOB);
		$this->streamDestRequest->expects($this->once())->method('deleteRelatedToActor')->with(self::BOB);

		$this->handler->activity($delete, $bob);
	}
}
