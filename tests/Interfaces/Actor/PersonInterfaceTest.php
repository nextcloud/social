<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Actor;

use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamDest;
use OCA\Social\Service\SignatureService;

require_once __DIR__ . '/ActorInterfaceTestCase.php';

class PersonInterfaceTest extends ActorInterfaceTestCase {
	private const CAROL = 'https://other.example/users/carol';

	protected function createHandler(): PersonInterface {
		return new PersonInterface(
			$this->actionsRequest,
			$this->cacheActorsRequest,
			$this->cacheDocumentsRequest,
			$this->followsRequest,
			$this->actorRelationRequest,
			$this->requestQueueRequest,
			$this->streamRequest,
			$this->streamDestRequest,
			$this->actorService,
			$this->configService,
			$this->streamActionsRequest,
			$this->reportsRequest,
			$this->filtersRequest,
			$this->listsRequest,
			$this->conversationsRequest,
			$this->featuredTagsRequest,
			$this->announcementsRequest,
		);
	}

	protected function createActor(): Person {
		return new Person();
	}

	/** An Update of bob's profile, signed by bob's server at the given time. */
	private function update(Person $bob, int $creationTime, string $origin = self::REMOTE_HOST): ACore {
		$update = $this->incoming(Update::TYPE, self::BOB . '#updates/1', self::BOB, $bob);
		$update->setOrigin($origin, SignatureService::ORIGIN_HEADER, $creationTime);

		return $update;
	}

	private function cachedBob(int $creation): Person {
		$cached = $this->bob();
		$cached->setCreation($creation);

		return $cached;
	}

	private function dest(string $streamId, string $subtype, string $type = 'recipient'): StreamDest {
		$dest = new StreamDest();
		$dest->setStreamId($streamId)
			->setActorId(self::BOB)
			->setType($type)
			->setSubtype($subtype);

		return $dest;
	}

	/** @param Stream[] $streams */
	private function storedStreams(array $streams): void {
		$byId = [];
		foreach ($streams as $stream) {
			$byId[$stream->getId()] = $stream;
		}
		$this->streamRequest->method('getStream')->willReturnCallback(function (string $id) use ($byId): Stream {
			if (!isset($byId[$id])) {
				throw new StreamNotFoundException();
			}

			return $byId[$id];
		});
	}

	public function testGetItemIsNotSupported(): void {
		$this->expectException(ItemNotFoundException::class);

		$this->handler->getItem($this->bob());
	}

	public function testUpdateNewerThanTheCachedProfileRefreshesIt(): void {
		$bob = $this->bob();
		$this->cacheActorsRequest->method('getFromId')->with(self::BOB)->willReturn($this->cachedBob(1000));

		$this->cacheActorsRequest->expects($this->once())->method('update')->with($this->identicalTo($bob));
		$this->cacheActorsRequest->expects($this->never())->method('save');

		$this->handler->activity($this->update($bob, 2000), $bob);

		$this->assertSame(2000, $bob->getCreation());
	}

	public function testUpdateOlderThanTheCachedProfileIsIgnored(): void {
		$bob = $this->bob();
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->cachedBob(3000));

		$this->cacheActorsRequest->expects($this->never())->method('update');
		$this->cacheActorsRequest->expects($this->never())->method('save');

		$this->handler->activity($this->update($bob, 2000), $bob);
	}

	public function testUpdateOfAnActorNotCachedYetCachesIt(): void {
		$this->nothingCached();
		$bob = $this->bob();

		$this->cacheActorsRequest->expects($this->once())->method('save')->with($this->identicalTo($bob));
		$this->cacheActorsRequest->expects($this->never())->method('update');

		$this->handler->activity($this->update($bob, 2000), $bob);
	}

	public function testUpdateNotComingFromTheActorsServerIsRefused(): void {
		$bob = $this->bob();

		$this->cacheActorsRequest->expects($this->never())->method('update');
		$this->cacheActorsRequest->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($this->update($bob, 2000, 'evil.example'), $bob);
	}

	public function testDeleteNotComingFromTheActorsServerIsRefused(): void {
		$bob = $this->bob();
		$delete = $this->incoming(Delete::TYPE, self::BOB . '#delete', self::BOB, $bob, 'evil.example');

		$this->cacheActorsRequest->expects($this->never())->method('deleteCacheById');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($delete, $bob);
	}

	public function testDeleteRemovesTheActorFromTheRecipientsOfRemainingStreams(): void {
		$bob = $this->bob();

		$addressed = new Note();
		$addressed->setId(self::REMOTE_URL . '/notes/addressed');
		$addressed->setToArray([self::BOB, self::CAROL]);

		$copied = new Note();
		$copied->setId(self::REMOTE_URL . '/notes/copied');
		$copied->setCcArray([$bob->getFollowers(), self::CAROL]);

		$unrelated = new Note();
		$unrelated->setId(self::REMOTE_URL . '/notes/unrelated');
		$unrelated->setToArray([self::CAROL]);

		$this->storedStreams([$addressed, $copied, $unrelated]);
		$this->streamDestRequest->method('getRelatedToActor')->with($this->identicalTo($bob))->willReturn([
			$this->dest($addressed->getId(), 'to'),
			$this->dest($copied->getId(), 'cc'),
			$this->dest($unrelated->getId(), 'to'),
			$this->dest(self::REMOTE_URL . '/notes/gone', 'to'),
			$this->dest($addressed->getId(), 'x', 'hashtag'),
		]);

		$updated = [];
		$this->streamRequest->method('update')->willReturnCallback(function (Stream $stream) use (&$updated): void {
			$updated[] = $stream->getId();
		});
		$this->streamRequest->expects($this->never())->method('deleteById');

		$this->handler->delete($bob);

		$this->assertSame([self::CAROL], array_values($addressed->getToArray()));
		$this->assertSame([self::CAROL], array_values($copied->getCcArray()));
		$this->assertSame([self::CAROL], $unrelated->getToArray());
		$this->assertEqualsCanonicalizing([$addressed->getId(), $copied->getId()], $updated);
	}

	public function testDeleteRemovesDirectMessagesSentToTheActor(): void {
		$bob = $this->bob();
		$direct = new Note();
		$direct->setId(self::REMOTE_URL . '/notes/direct');
		$direct->setTo(self::BOB);

		$this->storedStreams([$direct]);
		$this->streamDestRequest->method('getRelatedToActor')->willReturn([$this->dest($direct->getId(), 'to')]);

		$this->streamRequest->expects($this->once())->method('deleteById')->with($direct->getId());
		$this->streamRequest->expects($this->never())->method('update');

		$this->handler->delete($bob);
	}

	/**
	 * Filters and lists belong to one account and to nobody else, so they have
	 * nothing to outlive it. Nothing else removes them: without this they
	 * survive the account and keep muting and grouping for a user who is gone.
	 */
	public function testDeleteTakesTheAccountsFiltersAndListsWithIt(): void {
		$bob = $this->bob();

		$this->filtersRequest->expects($this->once())->method('deleteRelatedId')->with(self::BOB);
		$this->listsRequest->expects($this->once())->method('deleteRelatedId')->with(self::BOB);

		$this->handler->delete($bob);
	}

	public function testDeleteIgnoresItemsThatAreNotActors(): void {
		$this->actionsRequest->expects($this->never())->method('deleteByActor');
		$this->cacheActorsRequest->expects($this->never())->method('deleteCacheById');
		$this->streamRequest->expects($this->never())->method('deleteByAuthor');

		$this->handler->delete($this->note(self::REMOTE_URL . '/notes/1', self::BOB));
	}
}
