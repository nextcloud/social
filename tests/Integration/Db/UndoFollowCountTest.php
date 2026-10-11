<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Interfaces\Object\FollowInterface;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Service\SignatureService;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * An incoming Undo{Follow} against the real follow table and counters: the
 * follower count moves only when the stored follow the Undo names is the one
 * removed, so an Undo naming a follow id that is not stored changes nothing.
 *
 * All rows carry '-undofollow-' ids and are removed in tearDown.
 */
class UndoFollowCountTest extends TestCase {
	private const ALICE = 'https://cloud.example.org/undofollow/users/alice';
	private const BOB = 'https://remote.example/undofollow/users/bob';
	private const FOLLOW = 'https://remote.example/undofollow/follows/1';

	private FollowsRequest $followsRequest;
	private CacheActorsRequest $cacheActorsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->followsRequest = Server::get(FollowsRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([self::ALICE, self::BOB] as $id) {
			$this->followsRequest->deleteRelatedId($id);
			$this->cacheActorsRequest->deleteCacheById($id);
		}
	}

	private function cached(string $id, bool $local): void {
		$actor = new Person();
		$actor->setId($id);
		$actor->setPreferredUsername(basename($id));
		$actor->setAccount(basename($id) . '@' . parse_url($id, PHP_URL_HOST));
		$actor->setInbox($id . '/inbox');
		$actor->setFollowers($id . '/followers');
		$actor->setFollowing($id . '/following');
		$actor->setLocal($local);
		$this->cacheActorsRequest->save($actor);
	}

	/** bob follows alice, accepted and counted, as the Accept left it. */
	private function seed(): void {
		$this->cached(self::ALICE, true);
		$this->cached(self::BOB, false);
		$this->cacheActorsRequest->setCounts(self::ALICE, ['followers' => 1, 'following' => 0, 'post' => 0]);

		$follow = new Follow();
		$follow->setId(self::FOLLOW);
		$follow->setActorId(self::BOB);
		$follow->setObjectId(self::ALICE);
		$follow->setFollowId(self::ALICE . '/followers');
		$follow->setAccepted(true);
		$this->followsRequest->save($follow);
	}

	private function followers(): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('count_followers')
			->from('social_cache_actor')
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5(self::ALICE))));
		$cursor = $qb->executeQuery();
		$value = $cursor->fetchOne();
		$cursor->closeCursor();

		return (int)$value;
	}

	private function undo(string $followId): void {
		$follow = new Follow();
		$follow->setId($followId);
		$follow->setActorId(self::BOB);
		$follow->setObjectId(self::ALICE);

		$undo = new Undo();
		$undo->setId('https://remote.example/undofollow/undo/' . md5($followId));
		$undo->setActorId(self::BOB);
		$undo->setObject($follow);
		$undo->setOrigin('remote.example', SignatureService::ORIGIN_HEADER, time());

		Server::get(FollowInterface::class)->activity($undo, $follow);
	}

	private function stored(): bool {
		try {
			$this->followsRequest->getById(self::FOLLOW);

			return true;
		} catch (FollowNotFoundException) {
			return false;
		}
	}

	public function testAnUndoNamingAFollowThatIsNotStoredChangesNothing(): void {
		$this->seed();

		$this->undo('https://remote.example/undofollow/follows/not-stored');
		$this->undo('https://remote.example/undofollow/follows/not-stored-either');

		$this->assertSame(1, $this->followers(), 'the follower count is unchanged');
		$this->assertTrue($this->stored(), 'the stored follow is still there');
	}

	public function testAnUndoOfTheStoredFollowRemovesItAndTheCountOnce(): void {
		$this->seed();

		$this->undo(self::FOLLOW);
		$this->undo(self::FOLLOW);

		$this->assertFalse($this->stored());
		$this->assertSame(0, $this->followers(), 'counted down once, for the one row removed');
	}
}
