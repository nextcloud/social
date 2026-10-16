<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Federation;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Interfaces\Activity\MoveInterface;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCP\Server;

/**
 * A fediverse account moving, against the real database.
 *
 * The usual migration has the new account follow and be followed before the
 * `Move` arrives — it starts with importing the old account's follow list —
 * so the rows the move repoints collide with rows already there, on unique
 * indexes the database enforces. Any one of those refused would fail the whole
 * move, and the sender would retry it for days.
 */
class InboundMoveTest extends InboxTestCase {
	private const OLD = 'https://old.example/users/bob';
	private const NEW = 'https://new.example/users/bob';
	private const CAROL = 'https://other.example/users/carol';
	private const POST = self::OLD . '/statuses/116000000000000001';
	private const ADDRESSED = 'https://other.example/notes/addressed-to-bob';

	private FollowsRequest $follows;
	private Person $old;
	private Person $new;

	protected function hosts(): array {
		return ['old.example', 'new.example', 'other.example'];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->follows = Server::get(FollowsRequest::class);
		$this->old = $this->remoteActor(self::OLD, 'bob');
		$this->new = $this->remoteActor(self::NEW, 'bob', ['alsoKnownAs' => [self::OLD]]);
		$this->remoteActor(self::CAROL, 'carol');
		$this->forgetRecipients();
	}

	protected function tearDown(): void {
		$this->forgetRecipients();
		parent::tearDown();
	}

	private function forgetRecipients(): void {
		foreach ([md5(self::OLD), md5(self::NEW), md5(self::OLD . '/followers'), md5(self::NEW . '/followers')] as $prim) {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete(CoreRequestBuilder::TABLE_STREAM_DEST)
				->where($qb->expr()->eq('actor_id', $qb->createNamedParameter($prim)));
			$qb->executeStatement();
		}
	}

	/** @return list<string> the posts addressed to one account or collection */
	private function addressedTo(string $actorId): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('stream_id')
			->from(CoreRequestBuilder::TABLE_STREAM_DEST)
			->where($qb->expr()->eq('actor_id', $qb->createNamedParameter(md5($actorId))));
		$cursor = $qb->executeQuery();
		$streams = $cursor->fetchAll(\PDO::FETCH_COLUMN);
		$cursor->closeCursor();

		return $streams;
	}

	private function follow(string $id, string $actor, string $object, bool $accepted): void {
		$follow = new Follow();
		$follow->setId($id);
		$follow->setActorId($actor);
		$follow->setObjectId($object);
		$follow->setFollowId($object . '/followers');
		$follow->setAccepted($accepted);
		$this->assertTrue($this->follows->save($follow));
	}

	private function move(): void {
		Server::get(MoveInterface::class)->moveAccount($this->old, $this->new);
	}

	/** @return array<string, array{id: string, accepted: bool}> follower => its row */
	private function followersOf(string $object): array {
		$rows = [];
		foreach ($this->rows(CoreRequestBuilder::TABLE_FOLLOWS, 'object_id', $object) as $row) {
			if ($row['object_id'] === $object) {
				$rows[(string)$row['actor_id']] = ['id' => (string)$row['id'], 'accepted' => (bool)$row['accepted']];
			}
		}

		return $rows;
	}

	/** @return array<string, array{id: string, accepted: bool}> followed => its row */
	private function followingOf(string $actor): array {
		$rows = [];
		foreach ($this->rows(CoreRequestBuilder::TABLE_FOLLOWS, 'actor_id', $actor) as $row) {
			if ($row['actor_id'] === $actor) {
				$rows[(string)$row['object_id']] = ['id' => (string)$row['id'], 'accepted' => (bool)$row['accepted']];
			}
		}

		return $rows;
	}

	/**
	 * Someone here who followed both accounts keeps the follow the new
	 * account's server knows by its id, accepted because the old account had
	 * let them in. Nothing is sent: they had asked the new account already.
	 */
	public function testAFollowerOfBothAccountsKeepsOneAcceptedFollow(): void {
		$this->follow($this->alice() . '#follows/old', $this->alice(), self::OLD, true);
		$this->follow($this->alice() . '#follows/new', $this->alice(), self::NEW, false);
		$this->follow(self::CAROL . '#follows/old', self::CAROL, self::OLD, true);

		$this->move();

		$this->assertSame([], $this->followersOf(self::OLD));
		$this->assertEquals([
			$this->alice() => ['id' => $this->alice() . '#follows/new', 'accepted' => true],
			self::CAROL => ['id' => self::CAROL . '#follows/old', 'accepted' => true],
		], $this->followersOf(self::NEW));
		$this->assertSame([], $this->rows(CoreRequestBuilder::TABLE_REQUEST_QUEUE, 'author', $this->alice()));
	}

	/**
	 * A follow the new account has already asked for stays as it is, pending
	 * included: its server waits for the Accept the followed account sends
	 * when it lets the new account in.
	 */
	public function testAFollowTheNewAccountAlreadyAskedForIsKeptAsItIs(): void {
		$this->follow(self::OLD . '#follows/alice', self::OLD, $this->alice(), true);
		$this->follow(self::NEW . '#follows/alice', self::NEW, $this->alice(), false);
		$this->follow(self::OLD . '#follows/carol', self::OLD, self::CAROL, true);

		$this->move();

		$this->assertSame([], $this->followingOf(self::OLD));
		$this->assertEquals([
			$this->alice() => ['id' => self::NEW . '#follows/alice', 'accepted' => false],
			self::CAROL => ['id' => self::OLD . '#follows/carol', 'accepted' => true],
		], $this->followingOf(self::NEW));
	}

	/** A follow between the two accounts would become the new one following itself. */
	public function testAFollowBetweenTheTwoAccountsIsDropped(): void {
		$this->follow(self::NEW . '#follows/old', self::NEW, self::OLD, true);
		$this->follow(self::OLD . '#follows/new', self::OLD, self::NEW, true);

		$this->move();

		$this->assertSame([], $this->followersOf(self::OLD));
		$this->assertSame([], $this->followersOf(self::NEW));
		$this->assertSame([], $this->followingOf(self::NEW));
	}

	/** A post addressed to both accounts has one recipient row for the new one. */
	public function testAPostAddressedToBothAccountsIsAddressedToTheNewOneOnce(): void {
		Server::get(StreamDestRequest::class)->createRecipients(self::ADDRESSED, [
			self::OLD => ['recipient', 'to'],
			self::NEW => ['recipient', 'cc'],
			$this->old->getFollowers() => ['recipient', 'cc'],
		]);

		$this->move();

		$qb = $this->connection->getQueryBuilder();
		$qb->select('actor_id', 'subtype')
			->from(CoreRequestBuilder::TABLE_STREAM_DEST)
			->where($qb->expr()->eq('stream_id', $qb->createNamedParameter(md5(self::ADDRESSED))))
			->orderBy('actor_id');
		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		$expected = [
			['actor_id' => md5(self::NEW), 'subtype' => 'cc'],
			['actor_id' => md5($this->new->getFollowers()), 'subtype' => 'cc'],
		];
		usort($expected, static fn (array $a, array $b): int => strcmp($a['actor_id'], $b['actor_id']));
		$this->assertEquals($expected, $rows);
	}

	/** More posts than one page of the copy: every one of them is readdressed. */
	public function testEveryPostAddressedToTheOldAccountIsReaddressed(): void {
		$recipients = Server::get(StreamDestRequest::class);
		$count = CoreRequestBuilder::INSERT_IGNORE_CHUNK * 2 + 1;
		for ($i = 0; $i < $count; $i++) {
			$recipients->createRecipients('https://other.example/notes/' . $i, [
				$this->old->getFollowers() => ['recipient', 'cc'],
			]);
		}

		$this->move();

		$this->assertSame([], $this->addressedTo($this->old->getFollowers()));
		$this->assertCount($count, $this->addressedTo($this->new->getFollowers()));
	}

	/**
	 * The old account's posts stay its own, so its server can still edit and
	 * delete them after the move.
	 */
	public function testTheOldAccountCanStillDeleteItsPostsAfterTheMove(): void {
		$this->assertTrue($this->deliver((string)json_encode([
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => self::POST . '/activity',
			'type' => 'Create',
			'actor' => self::OLD,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'object' => [
				'id' => self::POST,
				'type' => 'Note',
				'attributedTo' => self::OLD,
				'to' => ['https://www.w3.org/ns/activitystreams#Public'],
				'content' => '<p>before the move</p>',
				'published' => '2026-05-01T10:00:00Z',
			],
		])));

		$this->move();

		$this->assertSame(self::OLD, $this->stored(self::POST)?->getAttributedTo());

		$this->assertTrue($this->deliver((string)json_encode([
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => self::POST . '#delete',
			'type' => 'Delete',
			'actor' => self::OLD,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'object' => ['id' => self::POST, 'type' => 'Tombstone'],
		])));

		$this->assertNull($this->stored(self::POST));
	}

	/** The sender retries a Move it got no answer to; the second finds nothing left to move. */
	public function testAMoveDeliveredTwiceChangesNothingTheSecondTime(): void {
		$this->follow(self::CAROL . '#follows/old', self::CAROL, self::OLD, true);
		$this->follow(self::CAROL . '#follows/new', self::CAROL, self::NEW, true);
		$this->follow(self::OLD . '#follows/carol', self::OLD, self::CAROL, true);

		$this->move();
		$followers = $this->followersOf(self::NEW);
		$following = $this->followingOf(self::NEW);

		$this->move();

		$this->assertSame($followers, $this->followersOf(self::NEW));
		$this->assertSame($following, $this->followingOf(self::NEW));
		$this->assertSame(self::NEW, $this->cacheActorsRequest->getFromId(self::OLD)->getMovedTo());
	}
}
