<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Model\ActorRelation;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ActorRelationRequest against the real database the CI job installs —
 * proving the migration created social_actor_relation with the right columns, that
 * the prim hashing and the unique index behave, and that every query the feature
 * relies on actually runs on SQLite / MySQL / PostgreSQL. None of this is reachable
 * from the mocked unit suite.
 */
class ActorRelationRequestTest extends TestCase {
	private ActorRelationRequest $request;

	private const ALICE = 'https://cloud.example.org/users/alice-itest';
	private const BOB = 'https://remote.example/users/bob-itest';
	private const CAROL = 'https://remote.example/users/carol-itest';

	protected function setUp(): void {
		parent::setUp();
		$this->request = Server::get(ActorRelationRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([self::ALICE, self::BOB, self::CAROL] as $id) {
			$this->request->deleteRelatedId($id);
		}
	}

	public function testSaveThenReadBackABlock(): void {
		$this->request->save(self::ALICE, self::BOB, ActorRelation::TYPE_BLOCK);

		$this->assertTrue($this->request->exists(self::ALICE, self::BOB, ActorRelation::TYPE_BLOCK));
		$this->assertFalse($this->request->exists(self::ALICE, self::BOB, ActorRelation::TYPE_MUTE));
		$this->assertFalse($this->request->exists(self::BOB, self::ALICE, ActorRelation::TYPE_BLOCK));

		$relation = $this->request->getRelation(self::ALICE, self::BOB, ActorRelation::TYPE_BLOCK);
		$this->assertNotNull($relation);
		$this->assertSame(self::BOB, $relation->getObjectId());
		$this->assertSame(ActorRelation::TYPE_BLOCK, $relation->getType());
	}

	public function testMuteStoresAndUpdatesTheNotificationsFlag(): void {
		$this->request->save(self::ALICE, self::BOB, ActorRelation::TYPE_MUTE, false);
		$relation = $this->request->getRelation(self::ALICE, self::BOB, ActorRelation::TYPE_MUTE);
		$this->assertNotNull($relation);
		$this->assertFalse($relation->isNotifications());

		// re-muting with a different flag updates in place rather than duplicating
		$this->request->save(self::ALICE, self::BOB, ActorRelation::TYPE_MUTE, true);
		$mutes = $this->request->getByActor(self::ALICE, ActorRelation::TYPE_MUTE);
		$this->assertCount(1, $mutes);
		$this->assertTrue($mutes[0]->isNotifications());
	}

	public function testTheSameActorCanBeBothMutedAndBlocked(): void {
		$this->request->save(self::ALICE, self::BOB, ActorRelation::TYPE_BLOCK);
		$this->request->save(self::ALICE, self::BOB, ActorRelation::TYPE_MUTE);

		$between = $this->request->getBetween(self::ALICE, self::BOB);
		$types = array_map(static fn (ActorRelation $r): string => $r->getType(), $between);
		sort($types);
		$this->assertSame([ActorRelation::TYPE_BLOCK, ActorRelation::TYPE_MUTE], $types);
	}

	public function testDeleteRemovesOnlyTheNamedRelation(): void {
		$this->request->save(self::ALICE, self::BOB, ActorRelation::TYPE_BLOCK);
		$this->request->save(self::ALICE, self::BOB, ActorRelation::TYPE_MUTE);

		$this->request->delete(self::ALICE, self::BOB, ActorRelation::TYPE_BLOCK);

		$this->assertFalse($this->request->exists(self::ALICE, self::BOB, ActorRelation::TYPE_BLOCK));
		$this->assertTrue($this->request->exists(self::ALICE, self::BOB, ActorRelation::TYPE_MUTE));
	}

	public function testGetByActorListsOnlyTheGivenType(): void {
		$this->request->save(self::ALICE, self::BOB, ActorRelation::TYPE_BLOCK);
		$this->request->save(self::ALICE, self::CAROL, ActorRelation::TYPE_BLOCK);
		$this->request->save(self::ALICE, self::CAROL, ActorRelation::TYPE_MUTE);

		$blocks = $this->request->getByActor(self::ALICE, ActorRelation::TYPE_BLOCK);
		$blocked = array_map(static fn (ActorRelation $r): string => $r->getObjectId(), $blocks);
		sort($blocked);
		$this->assertSame([self::BOB, self::CAROL], $blocked);
	}

	public function testDeleteRelatedIdRemovesRelationsInBothDirections(): void {
		$this->request->save(self::ALICE, self::BOB, ActorRelation::TYPE_BLOCK);
		$this->request->save(self::BOB, self::ALICE, ActorRelation::TYPE_BLOCKED_BY);

		$this->request->deleteRelatedId(self::BOB);

		$this->assertFalse($this->request->exists(self::ALICE, self::BOB, ActorRelation::TYPE_BLOCK));
		$this->assertFalse($this->request->exists(self::BOB, self::ALICE, ActorRelation::TYPE_BLOCKED_BY));
	}

	public function testSaveIsIdempotentUnderTheUniqueConstraint(): void {
		$this->request->save(self::ALICE, self::BOB, ActorRelation::TYPE_BLOCK);
		// a second identical save must not raise a unique-constraint error
		$this->request->save(self::ALICE, self::BOB, ActorRelation::TYPE_BLOCK);

		$this->assertCount(1, $this->request->getByActor(self::ALICE, ActorRelation::TYPE_BLOCK));
	}
}
