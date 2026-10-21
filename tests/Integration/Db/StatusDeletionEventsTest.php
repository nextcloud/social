<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StatusDeletionEventsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/** The streaming deletion cursor against the installed database. */
class StatusDeletionEventsTest extends TestCase {
	private StatusDeletionEventsRequest $events;
	private CacheActorsRequest $actors;
	private FollowsRequest $follows;
	private int $cursor = 0;
	/** @var list<int> */
	private array $eventIds = [];
	/** @var list<string> */
	private array $actorIds = [];
	/** @var list<string> */
	private array $followIds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->events = Server::get(StatusDeletionEventsRequest::class);
		$this->actors = Server::get(CacheActorsRequest::class);
		$this->follows = Server::get(FollowsRequest::class);
		$this->cursor = $this->events->newestId();
	}

	protected function tearDown(): void {
		if ($this->eventIds !== []) {
			$qb = Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
			$qb->delete(CoreRequestBuilder::TABLE_STATUS_DELETIONS)
				->where($qb->expr()->in('id', $qb->createNamedParameter($this->eventIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		foreach ($this->followIds as $id) {
			$this->follows->deleteById($id);
		}
		foreach ($this->actorIds as $id) {
			$this->actors->deleteCacheById($id);
		}
		parent::tearDown();
	}

	public function testRecordsPublicStatusIdAndStreamFilters(): void {
		$this->events->record((new Note())
			->setNid('9223372036854000000')
			->setHashtags(['Nextcloud', 'Mastodon'])
			->setVisibility(Stream::TYPE_PUBLIC)
			->setLocal(true));

		$rows = $this->events->getAfterId($this->cursor, 20);
		$event = array_values(array_filter($rows, fn (array $row): bool => $row['status_nid'] === '9223372036854000000'))[0] ?? null;
		$this->assertNotNull($event);
		$this->eventIds[] = $event['id'];
		$this->assertSame(['Nextcloud', 'Mastodon'], $event['hashtags']);
		$this->assertTrue($event['local']);
		$this->assertFalse($event['media']);
		$this->assertSame($event['id'], $this->events->newestId());
	}

	public function testRecordsDirectStatusDeletionOnlyForItsRecipients(): void {
		$this->events->record((new Note())
			->setNid('9223372036854000001')
			->setVisibility(Stream::TYPE_DIRECT), ['recipient-prim']);

		$event = array_values(array_filter(
			$this->events->getAfterId($this->cursor, 20),
			fn (array $row): bool => $row['status_nid'] === '9223372036854000001'
		))[0] ?? null;
		$this->assertNotNull($event);
		$this->eventIds[] = $event['id'];
		$this->assertSame(Stream::TYPE_DIRECT, $event['visibility']);
		$this->assertSame(['recipient-prim'], $event['recipients']);
		$this->assertSame([], $event['hashtags']);
	}

	public function testDoesNotRecordPrivateStatusWithoutRecipients(): void {
		$this->events->record((new Note())
			->setNid('9223372036854000002')
			->setVisibility(Stream::TYPE_FOLLOWERS));

		$ids = array_column($this->events->getAfterId($this->cursor, 20), 'status_nid');
		$this->assertNotContains('9223372036854000002', $ids);
	}

	public function testRecordsTheExactFollowersCollectionForPrivateDeletions(): void {
		$author = 'https://social.test/users/deletion-' . bin2hex(random_bytes(6));
		$followers = $author . '/followers';
		$actor = (new Person())
			->setId($author)
			->setAccount('deletion-' . bin2hex(random_bytes(4)) . '@social.test')
			->setFollowers($followers);
		$this->actors->save($actor);
		$this->actorIds[] = $author;

		$this->events->record((new Note())
			->setNid('9223372036854000003')
			->setAttributedTo($author)
			->setHashtags(['private-tag'])
			->setVisibility(Stream::TYPE_FOLLOWERS), [md5($followers)]);

		$event = array_values(array_filter(
			$this->events->getAfterId($this->cursor, 20),
			fn (array $row): bool => $row['status_nid'] === '9223372036854000003'
		))[0] ?? null;
		self::assertNotNull($event);
		$this->eventIds[] = $event['id'];
		self::assertSame(Stream::TYPE_FOLLOWERS, $event['visibility']);
		self::assertSame(md5($followers), $event['audience_prim']);
		self::assertSame([md5($followers)], $event['recipients']);
		self::assertSame([], $event['hashtags']);
	}

	public function testOnlyAcceptedFollowsMatchTheSavedFollowersCollection(): void {
		$followers = 'https://social.test/users/deletion-test/followers';
		$viewer = 'https://social.test/users/viewer-' . bin2hex(random_bytes(6));
		$followId = $viewer . '/follows/deletion-test';
		$follow = (new Follow())
			->setId($followId)
			->setActorId($viewer)
			->setObjectId('https://social.test/users/deletion-test')
			->setFollowId($followers)
			->setAccepted(true);
		$this->follows->save($follow);
		$this->followIds[] = $followId;

		self::assertTrue($this->follows->followsCollection($viewer, md5($followers)));
		self::assertFalse($this->follows->followsCollection($viewer, md5($followers . '/other')));
	}
}
