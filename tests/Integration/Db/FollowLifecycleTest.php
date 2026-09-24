<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use DateTime;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The follow table against the real database: pending vs accepted state, the
 * follower/following counts the profile pages show, and the account-move
 * repointing the Move handler relies on.
 */
class FollowLifecycleTest extends TestCase {
	private const ALICE = 'https://cloud.example.org/fltest/users/alice';
	private const BOB = 'https://remote.example/fltest/users/bob';
	private const NEW_BOB = 'https://new.example/fltest/users/bob';
	private const ASKER = 'https://asking.example/fltest/users/';

	private FollowsRequest $request;
	private CacheActorsRequest $cacheActorsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->request = Server::get(FollowsRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([self::ALICE, self::BOB, self::NEW_BOB] as $id) {
			$this->request->deleteRelatedId($id);
			$this->cacheActorsRequest->deleteCacheById($id);
		}
		for ($i = 0; $i < 5; $i++) {
			$this->request->deleteRelatedId(self::ASKER . $i);
		}
	}

	/** A cached actor with the inboxes a delivery would be addressed to. */
	private function cached(string $id, string $sharedInbox): void {
		$actor = new Person();
		$actor->setId($id);
		$actor->setAccount(md5($id) . '@remote.example');
		$actor->setInbox($id . '/inbox');
		$actor->setSharedInbox($sharedInbox);
		$actor->setFollowers($id . '/followers');
		$actor->setFollowing($id . '/following');
		$this->cacheActorsRequest->save($actor);
	}

	private function follow(string $id, string $actor, string $object, bool $accepted): Follow {
		$follow = new Follow();
		$follow->setId($id);
		$follow->setActorId($actor);
		$follow->setObjectId($object);
		$follow->setFollowId($object . '/followers');
		$follow->setAccepted($accepted);
		$this->request->save($follow);

		return $follow;
	}

	public function testAPendingFollowDoesNotCountUntilAccepted(): void {
		$this->follow(self::ALICE . '#follow/1', self::ALICE, self::BOB, false);

		$this->assertSame(0, $this->request->countFollowers(self::BOB), 'pending follows do not count');

		$stored = $this->request->getByPersons(self::ALICE, self::BOB);
		$this->assertFalse($stored->isAccepted());

		$this->request->accepted($stored);

		$this->assertSame(1, $this->request->countFollowers(self::BOB));
		$this->assertSame(1, $this->request->countFollowing(self::ALICE));
		$this->assertTrue($this->request->getByPersons(self::ALICE, self::BOB)->isAccepted());
	}

	public function testFollowerListsResolveBothDirections(): void {
		$this->follow(self::ALICE . '#follow/2', self::ALICE, self::BOB, true);

		$followers = $this->request->getFollowersByActorId(self::BOB);
		$this->assertCount(1, $followers);
		$this->assertSame(self::ALICE, $followers[0]->getActorId());

		$following = $this->request->getFollowingByActorId(self::ALICE);
		$this->assertCount(1, $following);
		$this->assertSame(self::BOB, $following[0]->getObjectId());
	}

	public function testMoveAccountRepointsFollowerRows(): void {
		$this->follow(self::ALICE . '#follow/3', self::ALICE, self::BOB, true);

		$newBob = new Person();
		$newBob->setId(self::NEW_BOB);
		$newBob->setFollowers(self::NEW_BOB . '/followers');
		$this->request->moveAccountFollowers(self::BOB, $newBob);

		$this->assertSame(0, $this->request->countFollowers(self::BOB), 'the old account keeps nothing');
		$this->assertSame(1, $this->request->countFollowers(self::NEW_BOB), 'the follower moved along');
		$this->assertSame(
			self::NEW_BOB,
			$this->request->getByPersons(self::ALICE, self::NEW_BOB)->getObjectId()
		);
	}

	public function testPendingRequestsAreListedAndCountedUntilDecided(): void {
		$this->follow(self::ALICE . '#follow/6', self::ALICE, self::BOB, false);
		$this->follow(self::NEW_BOB . '#follow/7', self::NEW_BOB, self::BOB, true);

		$pending = $this->request->getPendingByObjectId(self::BOB);
		$this->assertCount(1, $pending, 'only the unaccepted follow is a pending request');
		$this->assertSame(self::ALICE, $pending[0]->getActorId());
		$this->assertSame(1, $this->request->countPendingRequests(self::BOB));

		$this->request->accepted($pending[0]);

		$this->assertSame([], $this->request->getPendingByObjectId(self::BOB));
		$this->assertSame(0, $this->request->countPendingRequests(self::BOB));
		$this->assertSame(2, $this->request->countFollowers(self::BOB));
	}

	/**
	 * Five requests stamped with the same second, read two at a time: every
	 * one of them once, in the same order a single read gives, forwards along
	 * `max_id` and back along `min_id`.
	 */
	public function testPendingRequestsPageAcrossRequestsFromTheSameSecond(): void {
		for ($i = 0; $i < 5; $i++) {
			$this->follow(self::ASKER . $i . '#follow', self::ASKER . $i, self::BOB, false);
		}
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('social_follow')
			->set('creation', $qb->createNamedParameter(new DateTime('2026-01-01 12:00:00'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('object_id_prim', $qb->createNamedParameter(md5(self::BOB))))
			->executeStatement();

		$all = array_map(
			static fn (Follow $follow): string => $follow->getActorId(),
			$this->request->getPendingByObjectId(self::BOB)
		);
		$this->assertCount(5, $all);

		$read = [];
		$pages = [];
		$cursor = '';
		do {
			$page = $this->request->getPendingByObjectId(self::BOB, 2, $cursor);
			$pages[] = $page;
			foreach ($page as $follow) {
				$read[] = $follow->getActorId();
			}
			$cursor = $page === [] ? '' : FollowsRequest::pendingCursor(end($page));
		} while (count($page) === 2);

		$this->assertSame($all, $read, 'nothing skipped, nothing twice, the order kept');

		$back = $this->request->getPendingByObjectId(
			self::BOB, 2, '', FollowsRequest::pendingCursor($pages[2][0])
		);
		$this->assertSame(
			array_slice($all, 2, 2),
			array_map(static fn (Follow $follow): string => $follow->getActorId(), $back),
			'min_id hands back the page just before the cursor, newest first'
		);
	}

	public function testFollowerInboxesAreResolvedToOnePerInstance(): void {
		// this is what the delivery fan-out needs, and all it needs: the whole
		// follower list used to be hydrated into memory for it, one Person and
		// its details per follower, for every post
		$this->cached(self::ALICE, 'https://cloud.example.org/fltest/inbox');
		$this->cached(self::NEW_BOB, 'https://cloud.example.org/fltest/inbox');
		$this->follow(self::ALICE . '#follow/8', self::ALICE, self::BOB, true);
		$this->follow(self::NEW_BOB . '#follow/9', self::NEW_BOB, self::BOB, true);

		$this->assertSame(
			['https://cloud.example.org/fltest/inbox'],
			$this->request->getFollowerInboxes(self::BOB),
			'two followers on one instance are one delivery'
		);
	}

	public function testAFollowerWithNoSharedInboxIsDeliveredToPersonally(): void {
		$this->cached(self::ALICE, '');
		$this->follow(self::ALICE . '#follow/10', self::ALICE, self::BOB, true);

		$this->assertSame([self::ALICE . '/inbox'], $this->request->getFollowerInboxes(self::BOB));
	}

	public function testAPendingFollowerIsNotDeliveredTo(): void {
		$this->cached(self::ALICE, '');
		$this->follow(self::ALICE . '#follow/11', self::ALICE, self::BOB, false);

		$this->assertSame([], $this->request->getFollowerInboxes(self::BOB));
	}

	public function testAFollowerListCanBePaged(): void {
		$this->follow(self::ALICE . '#follow/12', self::ALICE, self::BOB, true);
		$this->follow(self::NEW_BOB . '#follow/13', self::NEW_BOB, self::BOB, true);

		$this->assertCount(2, $this->request->getFollowersByActorId(self::BOB));
		$this->assertCount(1, $this->request->getFollowersByActorId(self::BOB, 1));
		$this->assertCount(1, $this->request->getFollowersByActorId(self::BOB, 1, 1));
		$this->assertCount(0, $this->request->getFollowersByActorId(self::BOB, 1, 2));
	}

	public function testDeleteByIdFindsTheRowByItsPrimaryKey(): void {
		// the delete used to be LOWER(id) over a TEXT column while id_prim,
		// the primary key of the table, held exactly this
		$follow = $this->follow(self::ALICE . '#follow/14', self::ALICE, self::BOB, true);

		$this->request->deleteById($follow->getId());

		$this->assertSame(0, $this->request->countFollowers(self::BOB));
	}

	public function testDeleteByPersonsRemovesExactlyThatEdge(): void {
		$this->follow(self::ALICE . '#follow/4', self::ALICE, self::BOB, true);
		$this->follow(self::BOB . '#follow/5', self::BOB, self::ALICE, true);

		$edge = new Follow();
		$edge->setActorId(self::ALICE);
		$edge->setObjectId(self::BOB);
		$this->request->deleteByPersons($edge);

		$this->assertSame(0, $this->request->countFollowing(self::ALICE), 'alice→bob is gone');
		$this->assertSame(1, $this->request->countFollowers(self::ALICE), 'bob→alice survives');
	}

	public function testAccountSearchCanBeNarrowedToFollowedAccounts(): void {
		// a direct message's recipient box asks for the people the reader
		// follows first; narrowing only the first page of an ordinary search
		// found nobody when the followed account ranked below the limit
		foreach ([self::BOB => 'fltestbob@remote.example', self::NEW_BOB => 'fltestbobby@new.example'] as $id => $account) {
			$actor = new Person();
			$actor->setId($id);
			$actor->setAccount($account);
			$actor->setPreferredUsername('fltestbob');
			$actor->setInbox($id . '/inbox');
			$this->cacheActorsRequest->save($actor);
		}
		$this->follow(self::ALICE . '#follow/15', self::ALICE, self::BOB, false);
		$this->follow(self::ALICE . '#follow/16', self::ALICE, self::NEW_BOB, true);

		$ids = static fn (array $found): array => array_map(static fn (Person $actor): string => $actor->getId(), $found);

		$this->assertEqualsCanonicalizing(
			[self::BOB, self::NEW_BOB],
			$ids($this->cacheActorsRequest->searchAccounts('fltestbob'))
		);
		$this->assertSame(
			[self::NEW_BOB],
			$ids($this->cacheActorsRequest->searchAccounts('fltestbob', 1, self::ALICE)),
			'only an accepted follow counts, and the limit counts followed accounts'
		);
		$this->assertSame([], $this->cacheActorsRequest->searchAccounts('fltestbob', null, self::NEW_BOB));
	}
}
