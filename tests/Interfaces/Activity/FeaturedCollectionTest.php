<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Activity\AddInterface;
use OCA\Social\Interfaces\Activity\FeaturedCollection;
use OCA\Social\Interfaces\Activity\RemoveInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Add;
use OCA\Social\Model\ActivityPub\Activity\Remove;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\PinService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../ActivityPubTestCase.php';

/**
 * A remote account pinning and unpinning its own posts: `Add`/`Remove` with
 * the actor's `featured` collection as `target`. Mastodon sends `object` as a
 * bare URI, which is why both used to do nothing at all.
 */
class FeaturedCollectionTest extends ActivityPubTestCase {
	private const ACTOR = self::REMOTE_URL . '/users/bob';
	private const FEATURED = self::REMOTE_URL . '/users/bob/collections/featured';
	private const POST = self::REMOTE_URL . '/users/bob/statuses/1';

	/** @var CacheActorsRequest&MockObject */
	private $cacheActorsRequest;
	/** @var StreamRequest&MockObject */
	private $streamRequest;
	/** @var ActionsRequest&MockObject */
	private $actionsRequest;

	private FeaturedCollection $featured;

	protected function setUp(): void {
		parent::setUp();

		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->actionsRequest = $this->createMock(ActionsRequest::class);

		$this->featured = new FeaturedCollection(
			$this->cacheActorsRequest,
			$this->streamRequest,
			$this->actionsRequest,
			new NullLogger()
		);
	}

	private function actorKnown(string $featured = self::FEATURED): void {
		$actor = new Person();
		$actor->setId(self::ACTOR);
		$actor->setFeatured($featured);
		$this->cacheActorsRequest->method('getFromId')->with(self::ACTOR)->willReturn($actor);
	}

	private function postKnown(string $author = self::ACTOR): void {
		$note = new Note();
		$note->setId(self::POST);
		$note->setAttributedTo($author);
		$this->streamRequest->method('getStreamById')->willReturn($note);
	}

	private function notPinnedYet(): void {
		$this->actionsRequest->method('getAction')
			->willThrowException(new ActionDoesNotExistException());
		$this->actionsRequest->method('getActionsByActor')->willReturn([]);
	}

	/** @return Add|Remove */
	private function collectionActivity(string $type, string $target, string $objectId): ACore {
		$activity = $this->incoming($type, self::REMOTE_URL . '/activities/1', self::ACTOR);
		$activity->setObjectId($objectId);
		$activity->setTarget($target);

		return $activity;
	}

	public function testAddWithABareObjectUriPinsThePost(): void {
		$this->actorKnown();
		$this->postKnown();
		$this->notPinnedYet();

		/** @var ACore|null $saved */
		$saved = null;
		$this->capture($this->actionsRequest, 'save', $saved);

		(new AddInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Add::TYPE, self::FEATURED, self::POST)
		);

		$this->assertNotNull($saved);
		$this->assertSame(PinService::TYPE, $saved->getType());
		$this->assertSame(self::ACTOR, $saved->getActorId());
		$this->assertSame(self::POST, $saved->getObjectId());
		// the id has to name the actor too, or two accounts pinning the same
		// post would collide on the row id
		$this->assertSame(self::POST . '#pin/' . md5(self::ACTOR), $saved->getId());
	}

	public function testRemoveUnpinsThePost(): void {
		$this->actorKnown();
		$this->postKnown();

		$this->actionsRequest->expects($this->once())
			->method('deleteAction')
			->with(self::ACTOR, self::POST, PinService::TYPE);
		$this->actionsRequest->expects($this->never())->method('save');

		(new RemoveInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Remove::TYPE, self::FEATURED, self::POST)
		);
	}

	public function testAddWithAnEmbeddedObjectPinsThePost(): void {
		$this->actorKnown();
		$this->postKnown();
		$this->notPinnedYet();

		$note = new Note();
		$note->setId(self::POST);
		$activity = $this->incoming(Add::TYPE, self::REMOTE_URL . '/activities/1', self::ACTOR, $note);
		$activity->setTarget(self::FEATURED);

		$this->actionsRequest->expects($this->once())->method('save');

		(new AddInterface($this->featured))->processIncomingRequest($activity);
	}

	public function testAddTargetingAnythingElseThanTheFeaturedCollectionIsIgnored(): void {
		$this->actorKnown();
		$this->actionsRequest->expects($this->never())->method('save');
		$this->actionsRequest->expects($this->never())->method('deleteAction');

		(new AddInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Add::TYPE, self::REMOTE_URL . '/users/bob/collections/tags', self::POST)
		);
	}

	public function testAddWithoutATargetIsIgnored(): void {
		$this->actionsRequest->expects($this->never())->method('save');

		(new AddInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Add::TYPE, '', self::POST)
		);
	}

	public function testAddFromAnActorWeDoNotKnowIsIgnored(): void {
		$this->cacheActorsRequest->method('getFromId')
			->willThrowException(new CacheActorDoesNotExistException());
		$this->actionsRequest->expects($this->never())->method('save');

		(new AddInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Add::TYPE, self::FEATURED, self::POST)
		);
	}

	public function testAddOfAPostWeDoNotHoldIsIgnored(): void {
		$this->actorKnown();
		$this->streamRequest->method('getStreamById')
			->willThrowException(new StreamNotFoundException());
		$this->actionsRequest->expects($this->never())->method('save');

		(new AddInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Add::TYPE, self::FEATURED, self::POST)
		);
	}

	/**
	 * The featured collection is the actor's own, so it can only hold the
	 * actor's own posts — otherwise an account could pin a stranger's post to
	 * its profile.
	 */
	public function testAddOfSomebodyElsesPostIsRefused(): void {
		$this->actorKnown();
		$this->postKnown(self::REMOTE_URL . '/users/carol');
		$this->actionsRequest->expects($this->never())->method('save');

		(new AddInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Add::TYPE, self::FEATURED, self::POST)
		);
	}

	public function testAddFromAnotherHostThanTheActorIsRefused(): void {
		$this->expectException(InvalidOriginException::class);

		$activity = $this->incoming(
			Add::TYPE, self::REMOTE_URL . '/activities/1', 'https://elsewhere.example/users/bob'
		);
		$activity->setObjectId(self::POST);
		$activity->setTarget(self::FEATURED);

		(new AddInterface($this->featured))->processIncomingRequest($activity);
	}

	public function testAlreadyPinnedPostIsNotPinnedTwice(): void {
		$this->actorKnown();
		$this->postKnown();
		$this->actionsRequest->method('getAction')->willReturn(new Note());
		$this->actionsRequest->expects($this->never())->method('save');

		(new AddInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Add::TYPE, self::FEATURED, self::POST)
		);
	}

	public function testAPeerCannotPinWithoutLimit(): void {
		$this->actorKnown();
		$this->postKnown();
		$this->actionsRequest->method('getAction')
			->willThrowException(new ActionDoesNotExistException());
		$this->actionsRequest->method('getActionsByActor')
			->willReturn(array_fill(0, FeaturedCollection::MAX_REMOTE_PINS, new Note()));
		$this->actionsRequest->expects($this->never())->method('save');

		(new AddInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Add::TYPE, self::FEATURED, self::POST)
		);
	}
}
