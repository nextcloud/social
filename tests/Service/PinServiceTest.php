<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\PinService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PinServiceTest extends TestCase {
	private const AUTHOR = 'https://cloud.example/@alice';
	private const POST_ID = self::AUTHOR . '/notes/1';

	private StreamRequest|MockObject $streamRequest;
	private ActionsRequest|MockObject $actionsRequest;
	private PinService $service;
	private Person $author;

	protected function setUp(): void {
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->actionsRequest = $this->createMock(ActionsRequest::class);
		$this->actionsRequest->method('getAction')
			->willThrowException(new ActionDoesNotExistException());
		$this->service = new PinService($this->streamRequest, $this->actionsRequest);

		$this->author = new Person();
		$this->author->setId(self::AUTHOR);
		$this->author->setLocal(true);
	}

	private function ownPost(string $id = self::POST_ID): Note {
		$note = new Note();
		$note->setId($id);
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setLocal(true);

		return $note;
	}

	private function followersOnlyPost(string $id = self::POST_ID): Note {
		$note = $this->ownPost($id);
		$note->setTo(self::AUTHOR . '/followers');

		return $note;
	}

	/** @return ACore[] */
	private function pins(string ...$objectIds): array {
		return array_map(
			static function (string $objectId): ACore {
				$pin = new Like();
				$pin->setType(PinService::TYPE);
				$pin->setActorId(self::AUTHOR);
				$pin->setObjectId($objectId);

				return $pin;
			},
			$objectIds
		);
	}

	public function testPinStoresAPinRowForTheOwnPost(): void {
		$this->streamRequest->method('getStreamByNid')->with(42)->willReturn($this->ownPost());
		$this->actionsRequest->method('getActionsByActor')->willReturn([]);
		$this->actionsRequest->expects($this->once())->method('save')
			->with($this->callback(function (ACore $pin): bool {
				$this->assertSame(PinService::TYPE, $pin->getType());
				$this->assertSame(self::AUTHOR, $pin->getActorId());
				$this->assertSame(self::POST_ID, $pin->getObjectId());
				// the row id is the primary key of the actions table: it has to
				// name the actor, or two pins of one post would collide
				$this->assertSame(self::POST_ID . '#pin/' . md5(self::AUTHOR), $pin->getId());

				return true;
			}));

		$this->assertTrue($this->service->pin($this->author, 42)->isPinned());
	}

	public function testPinningAnAlreadyPinnedPostStoresNothingAgain(): void {
		$actionsRequest = $this->createMock(ActionsRequest::class);
		$actionsRequest->method('getAction')->willReturn($this->pins(self::POST_ID)[0]);
		$actionsRequest->expects($this->never())->method('save');
		$service = new PinService($this->streamRequest, $actionsRequest);
		$this->streamRequest->method('getStreamByNid')->willReturn($this->ownPost());

		$this->assertTrue($service->pin($this->author, 42)->isPinned());
	}

	public function testPinRefusesSomebodyElsesPost(): void {
		$foreign = $this->ownPost();
		$foreign->setAttributedTo('https://remote.example/users/bob');
		$this->streamRequest->method('getStreamByNid')->willReturn($foreign);
		$this->actionsRequest->expects($this->never())->method('save');

		$this->expectException(InvalidActionException::class);
		$this->service->pin($this->author, 42);
	}

	public function testPinRefusesAPostThatIsNotPublic(): void {
		$this->streamRequest->method('getStreamByNid')->willReturn($this->followersOnlyPost());
		$this->actionsRequest->expects($this->never())->method('save');

		$this->expectException(InvalidActionException::class);
		$this->service->pin($this->author, 42);
	}

	public function testPinRefusesARemotePost(): void {
		$remote = $this->ownPost();
		$remote->setLocal(false);
		$this->streamRequest->method('getStreamByNid')->willReturn($remote);
		$this->actionsRequest->expects($this->never())->method('save');

		$this->expectException(InvalidActionException::class);
		$this->service->pin($this->author, 42);
	}

	public function testPinRefusesMoreThanTheLimit(): void {
		$this->streamRequest->method('getStreamByNid')->willReturn($this->ownPost());
		$this->actionsRequest->method('getActionsByActor')->willReturn(
			$this->pins(...array_map(static fn (int $i): string => self::AUTHOR . '/notes/' . $i, range(1, PinService::MAX_PINS)))
		);
		$this->actionsRequest->expects($this->never())->method('save');

		$this->expectException(InvalidActionException::class);
		$this->service->pin($this->author, 42);
	}

	public function testUnpinRemovesThePinRow(): void {
		$this->streamRequest->method('getStreamByNid')->willReturn($this->ownPost());
		$this->actionsRequest->expects($this->once())->method('deleteAction')
			->with(self::AUTHOR, self::POST_ID, PinService::TYPE);

		$this->assertFalse($this->service->unpin($this->author, 42)->isPinned());
	}

	public function testUnpinRefusesSomebodyElsesPost(): void {
		$foreign = $this->ownPost();
		$foreign->setAttributedTo('https://remote.example/users/bob');
		$this->streamRequest->method('getStreamByNid')->willReturn($foreign);
		$this->actionsRequest->expects($this->never())->method('deleteAction');

		$this->expectException(InvalidActionException::class);
		$this->service->unpin($this->author, 42);
	}

	public function testGetPinnedIdsKeepsTheOrderOfThePins(): void {
		$this->actionsRequest->method('getActionsByActor')
			->with(self::AUTHOR, PinService::TYPE)
			->willReturn($this->pins(self::AUTHOR . '/notes/2', self::AUTHOR . '/notes/1'));

		$this->assertSame(
			[self::AUTHOR . '/notes/2', self::AUTHOR . '/notes/1'],
			$this->service->getPinnedIds(self::AUTHOR)
		);
	}

	public function testGetPinnedPostsResolvesThePinsAndFlagsThem(): void {
		$this->actionsRequest->method('getActionsByActor')
			->willReturn($this->pins(self::AUTHOR . '/notes/2', self::AUTHOR . '/notes/1'));
		$this->streamRequest->method('getStreamById')->willReturnCallback(
			fn (string $id): Note => $this->ownPost($id)
		);

		$posts = $this->service->getPinnedPosts(self::AUTHOR);

		$this->assertSame(
			[self::AUTHOR . '/notes/2', self::AUTHOR . '/notes/1'],
			array_map(static fn ($post): string => $post->getId(), $posts)
		);
		$this->assertTrue($posts[0]->isPinned());
		$this->assertTrue($posts[1]->isPinned());
	}

	public function testGetPinnedPostsWithoutAViewerStillFiltersOnVisibility(): void {
		$this->actionsRequest->method('getActionsByActor')->willReturn($this->pins(self::POST_ID));
		// the mock stands in for the query: asked as a viewer it applies
		// limitToViewer(), which without one is the public-only filter; asked
		// otherwise it applies no visibility condition at all
		$asViewer = null;
		$this->streamRequest->method('getStreamById')->willReturnCallback(
			function (string $id, bool $flag) use (&$asViewer): Note {
				$asViewer = $flag;

				return $this->ownPost($id);
			}
		);

		$this->service->getPinnedPosts(self::AUTHOR);

		$this->assertTrue($asViewer, 'the featured collection is read by anyone at all');
	}

	public function testAPinnedPostThatIsGoneIsSkipped(): void {
		$this->actionsRequest->method('getActionsByActor')
			->willReturn($this->pins(self::AUTHOR . '/notes/gone', self::POST_ID));
		$this->streamRequest->method('getStreamById')->willReturnCallback(
			function (string $id): Note {
				if (str_ends_with($id, 'gone')) {
					throw new StreamNotFoundException();
				}

				return $this->ownPost($id);
			}
		);

		$posts = $this->service->getPinnedPosts(self::AUTHOR);

		$this->assertCount(1, $posts, 'a deleted post never breaks the profile');
		$this->assertSame(self::POST_ID, $posts[0]->getId());
	}

	public function testMarkPinnedFlagsOnlyThePinnedPostsOfAPage(): void {
		$this->actionsRequest->expects($this->once())->method('getActionsByActor')
			->willReturn($this->pins(self::POST_ID));
		$page = [$this->ownPost(self::AUTHOR . '/notes/other'), $this->ownPost()];

		$this->service->markPinned($page, self::AUTHOR);

		$this->assertFalse($page[0]->isPinned());
		$this->assertTrue($page[1]->isPinned(), 'one query flags the whole page');
	}

	public function testMarkPinnedDoesNotQueryForAnEmptyPage(): void {
		$this->actionsRequest->expects($this->never())->method('getActionsByActor');

		$this->service->markPinned([], self::AUTHOR);
	}
}
