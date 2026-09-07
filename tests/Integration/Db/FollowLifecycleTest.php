<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
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

	private FollowsRequest $request;

	protected function setUp(): void {
		parent::setUp();
		$this->request = Server::get(FollowsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([self::ALICE, self::BOB, self::NEW_BOB] as $id) {
			$this->request->deleteRelatedId($id);
		}
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
}
