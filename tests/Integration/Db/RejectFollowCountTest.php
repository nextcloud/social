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
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Service\SignatureService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * An incoming Reject{Follow} against the real follow table and counters: the
 * followed account taking a follower off, or refusing a request. The
 * follower's own count moved when the request was written, the followed
 * account's when it was accepted, and a Reject moves back what moved.
 *
 * All rows carry '-rejectfollow-' ids and are removed in tearDown.
 */
class RejectFollowCountTest extends TestCase {
	private const ALICE = 'https://cloud.example.org/rejectfollow/users/alice';
	private const BOB = 'https://remote.example/rejectfollow/users/bob';
	private const FOLLOW = 'https://cloud.example.org/rejectfollow/follows/1';

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

	/**
	 * alice follows bob, as the request left it: on alice's following count
	 * from the start, on bob's follower count once accepted.
	 */
	private function seed(bool $accepted): void {
		$this->cached(self::ALICE, true);
		$this->cached(self::BOB, false);
		$this->cacheActorsRequest->setCounts(self::ALICE, ['followers' => 0, 'following' => 1, 'post' => 0]);
		$this->cacheActorsRequest->setCounts(self::BOB, ['followers' => $accepted ? 1 : 0, 'following' => 0, 'post' => 0]);

		$follow = new Follow();
		$follow->setId(self::FOLLOW);
		$follow->setActorId(self::ALICE);
		$follow->setObjectId(self::BOB);
		$follow->setFollowId(self::BOB . '/followers');
		$follow->setAccepted($accepted);
		$this->followsRequest->save($follow);
	}

	/** @return array{following: int, followers: int} alice's following, bob's followers */
	private function counts(): array {
		return [
			'following' => (int)($this->cacheActorsRequest->getCounts(self::ALICE)['following'] ?? -1),
			'followers' => (int)($this->cacheActorsRequest->getCounts(self::BOB)['followers'] ?? -1),
		];
	}

	/** bob's server rejects the follow it names, echoing it as Mastodon does. */
	private function reject(string $followId): void {
		$follow = new Follow();
		$follow->setId($followId);
		$follow->setActorId(self::ALICE);
		$follow->setObjectId(self::BOB);

		$reject = new Reject();
		$reject->setId('https://remote.example/rejectfollow/reject/' . md5($followId));
		$reject->setActorId(self::BOB);
		$reject->setObject($follow);
		$reject->setOrigin('remote.example', SignatureService::ORIGIN_HEADER, time());

		Server::get(FollowInterface::class)->activity($reject, $follow);
	}

	private function stored(): bool {
		try {
			$this->followsRequest->getById(self::FOLLOW);

			return true;
		} catch (FollowNotFoundException) {
			return false;
		}
	}

	public function testARejectOfAnAcceptedFollowRemovesItAndBothCounts(): void {
		$this->seed(true);

		$this->reject(self::FOLLOW);
		$this->reject(self::FOLLOW);

		$this->assertFalse($this->stored());
		$this->assertSame(['following' => 0, 'followers' => 0], $this->counts(), 'counted down once each, for the one row removed');
	}

	public function testARejectOfAPendingRequestRemovesItAndTheFollowersOwnCountOnly(): void {
		$this->seed(false);

		$this->reject(self::FOLLOW);

		$this->assertFalse($this->stored());
		$this->assertSame(['following' => 0, 'followers' => 0], $this->counts(), 'bob never counted a request');
	}

	public function testARejectNamingAFollowThatIsNotStoredChangesNothing(): void {
		$this->seed(true);

		$this->reject('https://cloud.example.org/rejectfollow/follows/not-stored');

		$this->assertTrue($this->stored(), 'the stored follow is still there');
		$this->assertSame(['following' => 1, 'followers' => 1], $this->counts());
	}
}
